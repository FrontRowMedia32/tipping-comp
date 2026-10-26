<?php
/**
 * Front end: [tipping] and [tipping_leaderboard] shortcodes and tip saving.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Frontend {

	public static function init() {
		add_shortcode( 'tipping', array( __CLASS__, 'shortcode_tipping' ) );
		add_shortcode( 'tipping_leaderboard', array( __CLASS__, 'shortcode_leaderboard' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_post_tipc_save_tips', array( __CLASS__, 'save_tips' ) );
		add_action( 'admin_post_nopriv_tipc_save_tips', array( __CLASS__, 'save_tips_logged_out' ) );
	}

	public static function register_assets() {
		wp_register_style( 'tipc', TIPC_URL . 'assets/tipc.css', array(), TIPC_VERSION );
		wp_register_script( 'tipc', TIPC_URL . 'assets/tipc.js', array(), TIPC_VERSION, true );
	}

	private static function enqueue() {
		if ( ! wp_style_is( 'tipc', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'tipc' );
		wp_enqueue_script( 'tipc' );
	}

	/* ---------- helpers ---------- */

	private static function resolve( $atts ) {
		$league = isset( $atts['league'] ) ? sanitize_key( $atts['league'] ) : '';
		if ( ! TIPC_Settings::is_valid_league( $league ) ) {
			return new WP_Error( 'tipc', 'Tipping: set league="nrl", "afl", "epl" or "nfl" on the shortcode.' );
		}
		$conf = TIPC_Settings::league( $league );
		if ( empty( $conf['enabled'] ) ) {
			return new WP_Error( 'tipc', $conf['label'] . ' isn\'t running right now.' );
		}
		$season = ! empty( $atts['season'] ) ? absint( $atts['season'] ) : (int) $conf['season'];
		return array( $league, $season, $conf );
	}

	private static function notice( $text, $type = 'info' ) {
		return '<div class="tipc-notice tipc-notice--' . esc_attr( $type ) . '">' . esc_html( $text ) . '</div>';
	}

	private static function ts( $mysql_utc ) {
		return strtotime( $mysql_utc . ' UTC' );
	}

	public static function is_open( $game ) {
		return 'scheduled' === $game->status && self::ts( $game->kickoff ) > time();
	}

	private static function logo( $url, $name ) {
		if ( ! TIPC_Settings::get( 'show_logos' ) || '' === (string) $url ) {
			return '';
		}
		return '<img class="tipc-logo" src="' . esc_url( $url ) . '" alt="" width="28" height="28" loading="lazy" decoding="async" onerror="this.remove()">';
	}

	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $uri );
	}

	private static function season_label( $league, $season ) {
		return 'epl' === $league ? $season . '/' . substr( (string) ( $season + 1 ), -2 ) : (string) $season;
	}

	/* ---------- [tipping] ---------- */

	public static function shortcode_tipping( $atts ) {
		$atts = shortcode_atts(
			array(
				'league'           => '',
				'season'           => '',
				'title'            => '',
				'show_leaderboard' => 'no',
			),
			$atts,
			'tipping'
		);

		$resolved = self::resolve( $atts );
		self::enqueue();
		if ( is_wp_error( $resolved ) ) {
			return self::notice( $resolved->get_error_message() );
		}
		list( $league, $season, $conf ) = $resolved;

		if ( ! defined( 'DONOTCACHEPAGE' ) && is_user_logged_in() ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$rounds = TIPC_DB::rounds( $league, $season );
		$title  = '' !== $atts['title'] ? $atts['title'] : $conf['label'] . ' ' . self::season_label( $league, $season );

		ob_start();
		echo '<div id="tipc-' . esc_attr( $league ) . '" class="tipc tipc-tipping tipc--' . esc_attr( $league ) . '">';
		echo '<div class="tipc-head"><h2 class="tipc-title">' . esc_html( $title ) . '</h2></div>';

		if ( empty( $rounds ) ) {
			echo self::notice( 'The draw hasn\'t been released yet. Check back soon.' ); // phpcs:ignore
			echo '</div>';
			return ob_get_clean();
		}

		$param = 'tipc_round_' . $league;
		$round = isset( $_GET[ $param ] ) ? (int) $_GET[ $param ] : TIPC_DB::current_round( $league, $season ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $rounds[ $round ] ) ) {
			$round = TIPC_DB::current_round( $league, $season );
		}

		// Save feedback.
		if ( isset( $_GET['tipc_l'] ) && $league === $_GET['tipc_l'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$saved   = isset( $_GET['tipc_saved'] ) ? (int) $_GET['tipc_saved'] : 0; // phpcs:ignore
			$skipped = isset( $_GET['tipc_skipped'] ) ? (int) $_GET['tipc_skipped'] : 0; // phpcs:ignore
			if ( $saved ) {
				echo self::notice( sprintf( _n( '%d tip saved.', '%d tips saved.', $saved, 'tipping-comp' ), $saved ), 'success' ); // phpcs:ignore
			}
			if ( $skipped ) {
				echo self::notice( sprintf( _n( '%d game had already started, so that tip wasn\'t changed.', '%d games had already started, so those tips weren\'t changed.', $skipped, 'tipping-comp' ), $skipped ), 'warning' ); // phpcs:ignore
			}
			if ( ! $saved && ! $skipped ) {
				echo self::notice( 'No changes to save.' ); // phpcs:ignore
			}
		}

		echo self::round_nav( $rounds, $round, $param ); // phpcs:ignore

		$games    = TIPC_DB::games( $league, $season, $round );
		$user_id  = get_current_user_id();
		$ids      = wp_list_pluck( $games, 'id' );
		$tips     = TIPC_DB::user_tips( $user_id, $ids );
		$first    = ! empty( $conf['margin'] ) ? TIPC_DB::first_games( $league, $season ) : array();
		$margin_g = isset( $first[ $round ] ) ? $first[ $round ] : 0;
		$any_open = false;
		foreach ( $games as $g ) {
			if ( self::is_open( $g ) ) {
				$any_open = true;
				break;
			}
		}

		if ( ! $user_id ) {
			$login = wp_login_url( self::current_url() );
			echo '<div class="tipc-login">';
			echo '<p>' . esc_html__( 'Log in to enter your tips.', 'tipping-comp' ) . '</p>';
			echo '<a class="tipc-button" href="' . esc_url( $login ) . '">' . esc_html__( 'Log in', 'tipping-comp' ) . '</a>';
			if ( get_option( 'users_can_register' ) ) {
				echo ' <a class="tipc-button tipc-button--ghost" href="' . esc_url( wp_registration_url() ) . '">' . esc_html__( 'Sign up', 'tipping-comp' ) . '</a>';
			}
			echo '</div>';
		}

		// Round score for this user.
		if ( $user_id ) {
			$finals  = 0;
			$correct = 0;
			foreach ( $games as $g ) {
				if ( 'final' === $g->status && null !== $g->winner ) {
					$finals++;
					$pick = isset( $tips[ (int) $g->id ] ) ? $tips[ (int) $g->id ]->pick : null;
					if ( TIPC_Scoring::is_correct( $pick, $g->winner, $conf['draw_rule'] ) ) {
						$correct++;
					}
				}
			}
			if ( $finals ) {
				echo '<p class="tipc-roundscore">' . esc_html( sprintf( 'Your round so far: %d correct from %d %s', $correct, $finals, _n( 'result', 'results', $finals, 'tipping-comp' ) ) ) . '</p>';
			}
		}

		$can_submit = $user_id && $any_open;
		if ( $can_submit ) {
			echo '<form class="tipc-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="tipc_save_tips">';
			echo '<input type="hidden" name="league" value="' . esc_attr( $league ) . '">';
			echo '<input type="hidden" name="round" value="' . esc_attr( $round ) . '">';
			echo '<input type="hidden" name="return" value="' . esc_attr( self::current_url() ) . '">';
			wp_nonce_field( 'tipc_save_' . $league, 'tipc_nonce' );
		}

		echo '<ol class="tipc-games">';
		foreach ( $games as $g ) {
			$tip = isset( $tips[ (int) $g->id ] ) ? $tips[ (int) $g->id ] : null;
			echo self::game_card( $g, $tip, $conf, $user_id && self::is_open( $g ), (int) $g->id === (int) $margin_g ); // phpcs:ignore
		}
		echo '</ol>';

		if ( $can_submit ) {
			echo '<div class="tipc-actions"><button type="submit" class="tipc-button tipc-save">' . esc_html__( 'Save tips', 'tipping-comp' ) . '</button>';
			echo '<span class="tipc-hint">' . esc_html__( 'Tips lock when each game kicks off. You can change them until then.', 'tipping-comp' ) . '</span></div>';
			echo '</form>';
		}

		if ( 'yes' === strtolower( $atts['show_leaderboard'] ) ) {
			echo self::shortcode_leaderboard( // phpcs:ignore
				array(
					'league' => $league,
					'season' => $season,
					'limit'  => 10,
					'filter' => 'no',
				)
			);
		}

		echo '</div>';
		return ob_get_clean();
	}

	private static function round_nav( $rounds, $round, $param ) {
		$keys = array_keys( $rounds );
		$pos  = array_search( $round, $keys, true );
		$prev = ( false !== $pos && $pos > 0 ) ? $keys[ $pos - 1 ] : null;
		$next = ( false !== $pos && $pos < count( $keys ) - 1 ) ? $keys[ $pos + 1 ] : null;
		$base = remove_query_arg( array( 'tipc_saved', 'tipc_skipped', 'tipc_l' ), self::current_url() );

		$html  = '<nav class="tipc-rounds" aria-label="Rounds">';
		$html .= $prev !== null
			? '<a class="tipc-rounds__step" href="' . esc_url( add_query_arg( $param, $prev, $base ) ) . '" aria-label="Previous round">&larr;</a>'
			: '<span class="tipc-rounds__step is-disabled" aria-hidden="true">&larr;</span>';
		$html .= '<select class="tipc-rounds__select" aria-label="Choose round" data-tipc-nav>';
		foreach ( $rounds as $r => $info ) {
			$html .= '<option value="' . esc_url( add_query_arg( $param, $r, $base ) ) . '"' . selected( $r, $round, false ) . '>' . esc_html( $info['label'] ) . '</option>';
		}
		$html .= '</select>';
		$html .= $next !== null
			? '<a class="tipc-rounds__step" href="' . esc_url( add_query_arg( $param, $next, $base ) ) . '" aria-label="Next round">&rarr;</a>'
			: '<span class="tipc-rounds__step is-disabled" aria-hidden="true">&rarr;</span>';
		$html .= '</nav>';
		return $html;
	}

	private static function game_card( $g, $tip, $conf, $open, $is_margin_game ) {
		$id      = (int) $g->id;
		$pick    = $tip ? $tip->pick : null;
		$ts      = self::ts( $g->kickoff );
		$final   = 'final' === $g->status && null !== $g->winner;
		$options = array(
			'home' => array( $g->home, $g->home_logo ),
		);
		if ( 'draw_pick' === $conf['draw_rule'] ) {
			$options['draw'] = array( 'Draw', '' );
		}
		$options['away'] = array( $g->away, $g->away_logo );

		$classes = array( 'tipc-game', 'is-' . $g->status );
		if ( $open ) {
			$classes[] = 'is-open';
		}
		if ( $final && $pick ) {
			$classes[] = TIPC_Scoring::is_correct( $pick, $g->winner, $conf['draw_rule'] ) ? 'is-correct' : 'is-wrong';
		}

		$html  = '<li class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		$html .= '<div class="tipc-game__meta">';
		$html .= '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( wp_date( 'D j M, g:ia', $ts ) ) . '</time>';
		if ( '' !== $g->venue ) {
			$html .= '<span class="tipc-game__venue">' . esc_html( $g->venue ) . '</span>';
		}
		$html .= self::status_badge( $g, $open, $pick, $conf );
		$html .= '</div>';

		$html .= '<fieldset class="tipc-picks" ' . ( $open ? '' : 'disabled' ) . '>';
		$html .= '<legend class="screen-reader-text">' . esc_html( $g->home . ' v ' . $g->away ) . '</legend>';
		foreach ( $options as $value => $opt ) {
			$is_winner = $final && $g->winner === $value;
			$score     = '';
			if ( 'draw' !== $value && null !== $g->home_score && in_array( $g->status, array( 'live', 'final' ), true ) ) {
				$score = '<span class="tipc-pick__score">' . esc_html( 'home' === $value ? $g->home_score : $g->away_score ) . '</span>';
			}
			$html .= '<label class="tipc-pick tipc-pick--' . esc_attr( $value ) . ( $is_winner ? ' is-winner' : '' ) . '">';
			$html .= '<input type="radio" name="tipc_pick[' . $id . ']" value="' . esc_attr( $value ) . '"' . checked( $pick, $value, false ) . ( $open ? '' : ' disabled' ) . '>';
			$html .= '<span class="tipc-pick__body">' . self::logo( $opt[1], $opt[0] ) . '<span class="tipc-pick__name">' . esc_html( $opt[0] ) . '</span>' . $score . '</span>';
			$html .= '</label>';
		}
		$html .= '</fieldset>';

		if ( $is_margin_game ) {
			$margin = $tip && null !== $tip->margin ? (int) $tip->margin : '';
			$html  .= '<div class="tipc-margin">';
			$html  .= '<label for="tipc-margin-' . $id . '">' . esc_html__( 'Tiebreaker: winning margin of your tipped team', 'tipping-comp' ) . '</label>';
			if ( $open ) {
				$html .= '<input id="tipc-margin-' . $id . '" type="number" inputmode="numeric" min="0" max="300" step="1" name="tipc_margin[' . $id . ']" value="' . esc_attr( $margin ) . '" placeholder="e.g. 12">';
			} else {
				$html .= '<span id="tipc-margin-' . $id . '" class="tipc-margin__value">' . esc_html( '' === $margin ? '–' : $margin ) . '</span>';
			}
			$html .= '</div>';
		}

		$html .= '</li>';
		return $html;
	}

	private static function status_badge( $g, $open, $pick, $conf ) {
		$label = '';
		$type  = 'neutral';
		switch ( $g->status ) {
			case 'live':
				$label = 'Live';
				$type  = 'live';
				break;
			case 'final':
				if ( $pick && null !== $g->winner ) {
					$ok    = TIPC_Scoring::is_correct( $pick, $g->winner, $conf['draw_rule'] );
					$label = $ok ? 'Correct' : 'Missed';
					$type  = $ok ? 'correct' : 'wrong';
				} else {
					$label = 'Full time';
				}
				break;
			case 'postponed':
				$label = 'Postponed';
				break;
			case 'cancelled':
				$label = 'Cancelled';
				break;
			default:
				if ( ! $open && is_user_logged_in() ) {
					$label = 'Locked';
				} elseif ( $open && ! $pick && is_user_logged_in() ) {
					$label = 'Not tipped';
					$type  = 'todo';
				}
		}
		return '' === $label ? '' : '<span class="tipc-badge tipc-badge--' . esc_attr( $type ) . '">' . esc_html( $label ) . '</span>';
	}

	/* ---------- [tipping_leaderboard] ---------- */

	public static function shortcode_leaderboard( $atts ) {
		$atts = shortcode_atts(
			array(
				'league' => '',
				'season' => '',
				'limit'  => 50,
				'filter' => 'yes',
				'title'  => '',
			),
			$atts,
			'tipping_leaderboard'
		);

		$resolved = self::resolve( $atts );
		self::enqueue();
		if ( is_wp_error( $resolved ) ) {
			return self::notice( $resolved->get_error_message() );
		}
		list( $league, $season, $conf ) = $resolved;

		$param = 'tipc_lb_' . $league;
		$round = isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ? (int) $_GET[ $param ] : null; // phpcs:ignore WordPress.Security.NonceVerification
		$data  = TIPC_Scoring::standings( $league, $season );
		if ( null !== $round && in_array( $round, $data['rounds'], true ) ) {
			$data = TIPC_Scoring::round_standings( $league, $season, $round );
		} else {
			$round = null;
		}

		$rounds_info = TIPC_DB::rounds( $league, $season );
		$last_round  = empty( $data['rounds'] ) ? null : max( $data['rounds'] );
		$limit       = max( 1, (int) $atts['limit'] );
		$me          = get_current_user_id();
		$title       = '' !== $atts['title'] ? $atts['title'] : ( null === $round ? 'Leaderboard' : 'Leaderboard: ' . ( isset( $rounds_info[ $round ] ) ? $rounds_info[ $round ]['label'] : 'Round ' . $round ) );

		ob_start();
		echo '<div class="tipc tipc-leaderboard tipc--' . esc_attr( $league ) . '">';
		echo '<div class="tipc-head"><h3 class="tipc-title">' . esc_html( $title ) . '</h3>';

		if ( 'yes' === strtolower( $atts['filter'] ) && ! empty( $data['rounds'] ) ) {
			$base = remove_query_arg( $param, self::current_url() );
			echo '<select class="tipc-rounds__select" aria-label="Leaderboard view" data-tipc-nav>';
			echo '<option value="' . esc_url( $base ) . '">' . esc_html__( 'Overall', 'tipping-comp' ) . '</option>';
			foreach ( array_reverse( TIPC_Scoring::standings( $league, $season )['rounds'] ) as $r ) {
				$label = isset( $rounds_info[ $r ] ) ? $rounds_info[ $r ]['label'] : 'Round ' . $r;
				echo '<option value="' . esc_url( add_query_arg( $param, $r, $base ) ) . '"' . selected( $round, $r, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}
		echo '</div>';

		if ( empty( $data['rows'] ) ) {
			echo self::notice( 'No results yet. The leaderboard fills in once games finish.' ); // phpcs:ignore
			echo '</div>';
			return ob_get_clean();
		}

		$show_last = null === $round && null !== $last_round;
		echo '<div class="tipc-table-wrap"><table class="tipc-table">';
		echo '<thead><tr><th scope="col" class="tipc-col-rank">#</th><th scope="col">' . esc_html__( 'Tipper', 'tipping-comp' ) . '</th>';
		echo '<th scope="col" class="tipc-num">' . esc_html__( 'Points', 'tipping-comp' ) . '</th>';
		echo '<th scope="col" class="tipc-num tipc-hide-sm">' . esc_html__( 'Correct', 'tipping-comp' ) . '</th>';
		if ( $show_last ) {
			echo '<th scope="col" class="tipc-num tipc-hide-sm">' . esc_html__( 'Last rd', 'tipping-comp' ) . '</th>';
		}
		if ( $data['use_margin'] ) {
			echo '<th scope="col" class="tipc-num" title="' . esc_attr__( 'Total margin tiebreaker difference (lower is better)', 'tipping-comp' ) . '">' . esc_html__( 'Margin', 'tipping-comp' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$shown_me = false;
		foreach ( $data['rows'] as $i => $row ) {
			$is_me = $me && (int) $row['user_id'] === $me;
			if ( $i >= $limit ) {
				if ( $is_me && ! $shown_me ) {
					echo '<tr class="tipc-gap"><td colspan="6">&hellip;</td></tr>';
					echo self::lb_row( $row, $is_me, $show_last, $last_round, $data['use_margin'] ); // phpcs:ignore
				}
				continue;
			}
			if ( $is_me ) {
				$shown_me = true;
			}
			echo self::lb_row( $row, $is_me, $show_last, $last_round, $data['use_margin'] ); // phpcs:ignore
		}
		echo '</tbody></table></div>';
		echo '</div>';
		return ob_get_clean();
	}

	private static function lb_row( $row, $is_me, $show_last, $last_round, $use_margin ) {
		$html  = '<tr' . ( $is_me ? ' class="is-me"' : '' ) . '>';
		$html .= '<td class="tipc-col-rank">' . esc_html( $row['rank'] ) . '</td>';
		$html .= '<td class="tipc-col-name">' . esc_html( $row['name'] ) . ( $is_me ? ' <span class="tipc-you">' . esc_html__( 'You', 'tipping-comp' ) . '</span>' : '' ) . '</td>';
		$html .= '<td class="tipc-num tipc-points">' . esc_html( $row['points'] ) . '</td>';
		$html .= '<td class="tipc-num tipc-hide-sm">' . esc_html( $row['correct'] ) . '</td>';
		if ( $show_last ) {
			$html .= '<td class="tipc-num tipc-hide-sm">' . esc_html( isset( $row['round_points'][ $last_round ] ) ? $row['round_points'][ $last_round ] : 0 ) . '</td>';
		}
		if ( $use_margin ) {
			$html .= '<td class="tipc-num">' . esc_html( $row['margin_diff'] ) . '</td>';
		}
		$html .= '</tr>';
		return $html;
	}

	/* ---------- saving ---------- */

	public static function save_tips_logged_out() {
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : home_url( '/' ); // phpcs:ignore
		wp_safe_redirect( wp_login_url( $return ) );
		exit;
	}

	public static function save_tips() {
		$league = isset( $_POST['league'] ) ? sanitize_key( wp_unslash( $_POST['league'] ) ) : '';
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : '';
		$return = wp_validate_redirect( $return, home_url( '/' ) );

		if ( ! TIPC_Settings::is_valid_league( $league ) || ! isset( $_POST['tipc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tipc_nonce'] ) ), 'tipc_save_' . $league ) ) {
			wp_die( esc_html__( 'Your session expired. Please go back, refresh the page and try again.', 'tipping-comp' ), 403 );
		}

		$conf = TIPC_Settings::league( $league );
		if ( empty( $conf['enabled'] ) ) {
			wp_die( esc_html__( 'This competition isn\'t running.', 'tipping-comp' ), 403 );
		}

		$user_id = get_current_user_id();
		$season  = (int) $conf['season'];
		$allowed = array( 'home', 'away' );
		if ( 'draw_pick' === $conf['draw_rule'] ) {
			$allowed[] = 'draw';
		}
		$first   = ! empty( $conf['margin'] ) ? array_flip( TIPC_DB::first_games( $league, $season ) ) : array();
		$picks   = isset( $_POST['tipc_pick'] ) && is_array( $_POST['tipc_pick'] ) ? wp_unslash( $_POST['tipc_pick'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$margins = isset( $_POST['tipc_margin'] ) && is_array( $_POST['tipc_margin'] ) ? wp_unslash( $_POST['tipc_margin'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$saved   = 0;
		$skipped = 0;
		$ids     = array_unique( array_merge( array_map( 'intval', array_keys( $picks ) ), array_map( 'intval', array_keys( $margins ) ) ) );
		$current = TIPC_DB::user_tips( $user_id, $ids );

		foreach ( $ids as $game_id ) {
			$game = TIPC_DB::get_game( $game_id );
			if ( ! $game || $game->league !== $league || (int) $game->season !== $season ) {
				continue;
			}
			$existing = isset( $current[ $game_id ] ) ? $current[ $game_id ] : null;
			$pick     = isset( $picks[ $game_id ] ) ? sanitize_key( $picks[ $game_id ] ) : ( $existing ? $existing->pick : '' );
			if ( ! in_array( $pick, $allowed, true ) ) {
				continue;
			}

			$margin = null;
			if ( isset( $first[ $game_id ] ) ) {
				$raw = isset( $margins[ $game_id ] ) ? trim( (string) $margins[ $game_id ] ) : '';
				if ( '' !== $raw && is_numeric( $raw ) ) {
					$margin = max( 0, min( 300, (int) $raw ) );
				}
				if ( 'draw' === $pick ) {
					$margin = 0;
				}
			}

			// Nothing changed? Don't touch it.
			if ( $existing && $existing->pick === $pick && (string) $existing->margin === (string) $margin ) {
				continue;
			}

			if ( ! self::is_open( $game ) ) {
				$skipped++;
				continue;
			}

			if ( TIPC_DB::save_tip( $user_id, $game_id, $pick, $margin ) ) {
				$saved++;
			}
		}

		if ( $saved ) {
			do_action( 'tipc_tips_saved', $user_id, $league, $season );
		}

		$return = remove_query_arg( array( 'tipc_saved', 'tipc_skipped', 'tipc_l' ), $return );
		$return = add_query_arg(
			array(
				'tipc_saved'   => $saved,
				'tipc_skipped' => $skipped,
				'tipc_l'       => $league,
			),
			$return
		);
		wp_safe_redirect( $return . '#tipc-' . $league );
		exit;
	}
}
