<?php
/**
 * Admin screens: settings, fixtures & results, sync tools.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_head', array( __CLASS__, 'menu_icon_css' ) );
		add_action( 'admin_post_tipc_sync_now', array( __CLASS__, 'handle_sync_now' ) );
		add_action( 'admin_post_tipc_test_feed', array( __CLASS__, 'handle_test_feed' ) );
		add_action( 'admin_post_tipc_save_games', array( __CLASS__, 'handle_save_games' ) );
		add_action( 'admin_post_tipc_delete_season', array( __CLASS__, 'handle_delete_season' ) );
		add_action( 'admin_post_tipc_run_tick', array( __CLASS__, 'handle_run_tick' ) );
		add_action( 'admin_post_tipc_odds_check', array( __CLASS__, 'handle_odds_check' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TIPC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=tipc-settings' ) ) . '">Settings</a>' );
		return $links;
	}

	public static function menu() {
		add_menu_page( 'Tipping Comp', 'Tipping Comp', self::CAP, 'tipc-settings', array( __CLASS__, 'page_settings' ), 'none', 58 );
		add_submenu_page( 'tipc-settings', 'Tipping Comp Settings', 'Settings', self::CAP, 'tipc-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'tipc-settings', 'Fixtures & Results', 'Fixtures & Results', self::CAP, 'tipc-games', array( __CLASS__, 'page_games' ) );
	}

	public static function register_settings() {
		register_setting(
			'tipc',
			TIPC_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'TIPC_Settings', 'sanitize' ),
			)
		);
	}

	/**
	 * Front Row Media logo mark in white as the menu icon. Set as a CSS
	 * background so WordPress doesn't recolour it to the admin grey.
	 */
	public static function menu_icon_css() {
		$file = TIPC_DIR . 'assets/menu-icon.svg';
		if ( ! file_exists( $file ) ) {
			return;
		}
		$uri = 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore
		echo '<style id="tipc-menu-icon">#adminmenu #toplevel_page_tipc-settings .wp-menu-image{background-image:url(' . esc_attr( $uri ) . ');background-repeat:no-repeat;background-position:center;background-size:24px 24px;}#adminmenu #toplevel_page_tipc-settings .wp-menu-image:before{content:none;}</style>' . "\n";
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'tipc-' ) ) {
			return;
		}
		wp_add_inline_style( 'common', self::css() );
	}

	private static function css() {
		return '
.tipc-admin .tipc-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin:16px 0}
.tipc-admin .tipc-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px}
.tipc-admin .tipc-card h3{margin-top:0;display:flex;align-items:center;justify-content:space-between;gap:8px}
.tipc-admin .tipc-card label{display:block;margin:10px 0 4px;font-weight:600}
.tipc-admin .tipc-card .regular-text,.tipc-admin .tipc-card select{width:100%;max-width:100%}
.tipc-admin .tipc-card .tipc-inline{display:flex;align-items:center;gap:6px;font-weight:400;margin:8px 0}
.tipc-admin .tipc-pill{font-size:11px;padding:2px 8px;border-radius:99px;background:#f0f0f1}
.tipc-admin .tipc-pill.on{background:#d1f0d9;color:#0a5c25}
.tipc-admin .tipc-pill.err{background:#fde2e1;color:#8a1f11}
.tipc-admin code.tipc-cmd{display:block;padding:8px 10px;margin:6px 0;white-space:pre-wrap;word-break:break-all}
.tipc-admin .tipc-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0}
.tipc-admin .tipc-toolbar form{display:inline-flex;gap:6px;align-items:center;margin:0}
.tipc-admin table.tipc-games input.small-text{width:60px}
.tipc-admin table.tipc-games td{vertical-align:middle}
.tipc-admin .tipc-log td{font-size:12px}
.tipc-admin .tipc-sample td{font-size:12px}';
	}

	private static function require_cap() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
	}

	private static function games_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'tipc-games' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function fmt_time( $ts ) {
		return $ts ? wp_date( 'D j M Y, g:ia', $ts ) : 'never';
	}

	/* ---------- Settings page ---------- */

	public static function page_settings() {
		self::require_cap();
		$s       = TIPC_Settings::all();
		$leagues = TIPC_Settings::leagues();
		$rules   = TIPC_Settings::draw_rules();
		$name    = TIPC_Settings::OPTION;
		?>
		<div class="wrap tipc-admin">
			<h1>Tipping Comp</h1>
			<p>Turn on the competitions you want, then add the shortcodes to any page (they work in Breakdance's Shortcode element). Fixtures and results load automatically.</p>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'tipc' ); ?>

				<h2>Competitions</h2>
				<div class="tipc-cards">
				<?php
				foreach ( $leagues as $key => $info ) :
					$l     = $s['leagues'][ $key ];
					$base  = $name . '[leagues][' . $key . ']';
					$count = TIPC_DB::count_games( $key, $l['season'] );
					?>
					<div class="tipc-card">
						<h3><?php echo esc_html( $info['name'] ); ?>
							<span class="tipc-pill <?php echo $l['enabled'] ? 'on' : ''; ?>"><?php echo $l['enabled'] ? 'Running' : 'Off'; ?></span>
						</h3>
						<label class="tipc-inline"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" <?php checked( $l['enabled'] ); ?>> Run this competition</label>

						<label for="tipc-<?php echo esc_attr( $key ); ?>-label">Competition name</label>
						<input id="tipc-<?php echo esc_attr( $key ); ?>-label" class="regular-text" type="text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( $l['label'] ); ?>">

						<label for="tipc-<?php echo esc_attr( $key ); ?>-season">Season<?php echo 'epl' === $key ? ' (start year: 2026 = 2026/27)' : ''; ?></label>
						<input id="tipc-<?php echo esc_attr( $key ); ?>-season" class="small-text" type="number" min="2000" max="2100" name="<?php echo esc_attr( $base ); ?>[season]" value="<?php echo esc_attr( $l['season'] ); ?>">
						<span class="description"><?php echo esc_html( $count ); ?> games loaded</span>

						<label for="tipc-<?php echo esc_attr( $key ); ?>-provider">Data source</label>
						<select id="tipc-<?php echo esc_attr( $key ); ?>-provider" name="<?php echo esc_attr( $base ); ?>[provider]">
							<?php foreach ( $info['providers'] as $pk => $plabel ) : ?>
								<option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $l['provider'], $pk ); ?>><?php echo esc_html( $plabel ); ?></option>
							<?php endforeach; ?>
						</select>

						<label for="tipc-<?php echo esc_attr( $key ); ?>-results">Results from</label>
						<select id="tipc-<?php echo esc_attr( $key ); ?>-results" name="<?php echo esc_attr( $base ); ?>[results_source]">
							<option value="feed" <?php selected( $l['results_source'], 'feed' ); ?>>The data source above</option>
							<option value="oddsapi" <?php selected( $l['results_source'], 'oddsapi' ); ?>>The Odds API (data source above as backup)</option>
						</select>
						<?php if ( 'oddsapi' === $l['results_source'] && '' === $s['keys']['oddsapi'] ) : ?>
							<p class="description" style="color:#b32d2e">Add your Odds API key below, or results will come from the data source.</p>
						<?php endif; ?>

						<label for="tipc-<?php echo esc_attr( $key ); ?>-draw">Draws</label>
						<select id="tipc-<?php echo esc_attr( $key ); ?>-draw" name="<?php echo esc_attr( $base ); ?>[draw_rule]">
							<?php foreach ( $rules as $rk => $rlabel ) : ?>
								<option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $l['draw_rule'], $rk ); ?>><?php echo esc_html( $rlabel ); ?></option>
							<?php endforeach; ?>
						</select>

						<label for="tipc-<?php echo esc_attr( $key ); ?>-missed">Missed tips</label>
						<select id="tipc-<?php echo esc_attr( $key ); ?>-missed" name="<?php echo esc_attr( $base ); ?>[missed_tips]">
							<option value="none" <?php selected( $l['missed_tips'], 'none' ); ?>>No tip = no points</option>
							<option value="away" <?php selected( $l['missed_tips'], 'away' ); ?>>No tip = away team (once a tipper has joined)</option>
						</select>

						<label for="tipc-<?php echo esc_attr( $key ); ?>-points">Points per correct tip</label>
						<input id="tipc-<?php echo esc_attr( $key ); ?>-points" class="small-text" type="number" min="1" max="100" name="<?php echo esc_attr( $base ); ?>[points]" value="<?php echo esc_attr( $l['points'] ); ?>">

						<label class="tipc-inline"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[margin]" value="1" <?php checked( $l['margin'] ); ?>> Margin tiebreaker on the first game of each round</label>

						<p class="description">Shortcodes:<br>
							<code>[tipping league="<?php echo esc_html( $key ); ?>"]</code><br>
							<code>[tipping_leaderboard league="<?php echo esc_html( $key ); ?>"]</code>
						</p>
					</div>
				<?php endforeach; ?>
				</div>

				<h2>API keys</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tipc-key-odds">The Odds API key</label></th>
						<td><input id="tipc-key-odds" class="regular-text" type="text" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[keys][oddsapi]" value="<?php echo esc_attr( $s['keys']['oddsapi'] ); ?>">
						<p class="description">Reliable results for NRL, AFL, EPL and NFL. From <a href="https://the-odds-api.com" target="_blank" rel="noopener">the-odds-api.com</a>; set "Results from" to The Odds API on each competition.
						<?php
						$usage = TIPC_OddsAPI::usage();
						if ( $usage ) {
							echo '<br><strong>' . esc_html( sprintf( 'Credits left this month: %s (used %s), as of %s.', number_format_i18n( $usage['remaining'] ), number_format_i18n( $usage['used'] ), self::fmt_time( $usage['time'] ) ) ) . '</strong>';
						}
						?>
						</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="tipc-key-fd">football-data.org key</label></th>
						<td><input id="tipc-key-fd" class="regular-text" type="text" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[keys][footballdata]" value="<?php echo esc_attr( $s['keys']['footballdata'] ); ?>">
						<p class="description">Needed for the Premier League. Free at <a href="https://www.football-data.org/client/register" target="_blank" rel="noopener">football-data.org</a>.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="tipc-key-tsdb">TheSportsDB key</label></th>
						<td><input id="tipc-key-tsdb" class="regular-text" type="text" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[keys][thesportsdb]" value="<?php echo esc_attr( $s['keys']['thesportsdb'] ); ?>">
						<p class="description">Optional backup source for any league. Full seasons need a premium key from <a href="https://www.thesportsdb.com/pricing" target="_blank" rel="noopener">thesportsdb.com</a>.</p></td>
					</tr>
				</table>

				<h2>General</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tipc-email">Contact email for data feeds</label></th>
						<td><input id="tipc-email" class="regular-text" type="email" name="<?php echo esc_attr( $name ); ?>[contact_email]" value="<?php echo esc_attr( $s['contact_email'] ); ?>">
						<p class="description">Sent to Squiggle (AFL) in the request header, as they ask API users to identify themselves.</p></td>
					</tr>
					<tr>
						<th scope="row">Display</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_logos]" value="1" <?php checked( $s['show_logos'] ); ?>> Show team logos from the data feed</label></td>
					</tr>
					<tr>
						<th scope="row">Caching</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[purge_cache]" value="1" <?php checked( $s['purge_cache'] ); ?>> Clear the page cache (SiteGround Speed Optimizer and others) when results come in</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="tipc-repo">Updates</label></th>
						<td><input id="tipc-repo" class="regular-text" type="text" placeholder="<?php echo esc_attr( TIPC_Updater::DEFAULT_REPO ? TIPC_Updater::DEFAULT_REPO : 'owner/repository' ); ?>" name="<?php echo esc_attr( $name ); ?>[update_repo]" value="<?php echo esc_attr( $s['update_repo'] ); ?>">
						<p class="description">GitHub repository that publishes Tipping Comp releases<?php echo TIPC_Updater::DEFAULT_REPO ? ' (leave blank to use ' . esc_html( TIPC_Updater::DEFAULT_REPO ) . ')' : ''; ?>. Turn on <strong>Enable auto-updates</strong> for Tipping Comp on the Plugins screen to install new versions automatically.
						<?php echo TIPC_Updater::repo() ? '<br>Checking for updates from <code>' . esc_html( TIPC_Updater::repo() ) . '</code>.' : ''; ?></p>
						<label for="tipc-gh-token" style="display:block;margin-top:8px">GitHub token (only for a private repository)</label>
						<input id="tipc-gh-token" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[keys][github]" value="<?php echo esc_attr( $s['keys']['github'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Uninstall</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> Delete all fixtures, tips and settings when the plugin is deleted</label></td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h2>Automatic updates</h2>
				<?php self::cron_box( $s ); ?>

				<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[regenerate_cron_key]" value="1"> Generate a new secret cron URL when I save</label></p>
				<?php submit_button( 'Save settings', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	private static function cron_box( $s ) {
		$next     = wp_next_scheduled( TIPC_Sync::HOOK );
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$path     = untrailingslashit( ABSPATH ) . '/wp-cron.php';
		$secret   = add_query_arg( 'tipc_cron', $s['cron_key'], home_url( '/' ) );
		?>
		<div class="tipc-card" style="max-width:900px">
			<p><strong>Next check:</strong> <?php echo esc_html( $next ? self::fmt_time( $next ) : 'not scheduled' ); ?>
				&nbsp;·&nbsp; <strong>WP-Cron on page visits:</strong> <?php echo $disabled ? 'off (server cron in use)' : 'on'; ?></p>
			<p>Every 15 minutes the plugin checks for games in progress and pulls results; every 6 hours it refreshes the whole draw to catch time changes. When nothing is on it does nothing.</p>

			<h3>Set up on SiteGround (recommended)</h3>
			<ol>
				<li>In <strong>Site Tools &rarr; Devs &rarr; Cron Jobs</strong>, add this command and set it to run every 15 minutes (<code>*/15 * * * *</code>):
					<code class="tipc-cmd">php -q <?php echo esc_html( $path ); ?> &gt;/dev/null 2&gt;&amp;1</code></li>
				<li>Add this line to <code>wp-config.php</code>, above <em>"That's all, stop editing!"</em>, so WordPress stops running cron on page visits:
					<code class="tipc-cmd">define( 'DISABLE_WP_CRON', true );</code></li>
			</ol>
			<p class="description">Do step 1 before step 2, otherwise scheduled tasks (including other plugins') stop running.</p>

			<h3>Alternative: secret URL</h3>
			<p>Calling this URL runs a check straight away. Keep it private.</p>
			<code class="tipc-cmd"><?php echo esc_html( $secret ); ?></code>

			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tipc_run_tick' ), 'tipc_run_tick' ) ); ?>">Run a check now</a></p>
		</div>
		<?php
	}

	/* ---------- Fixtures & results page ---------- */

	public static function page_games() {
		self::require_cap();
		$leagues = TIPC_Settings::leagues();
		$league  = isset( $_GET['league'] ) ? sanitize_key( $_GET['league'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $leagues[ $league ] ) ) {
			$enabled = array_keys( TIPC_Settings::enabled_leagues() );
			$league  = $enabled ? $enabled[0] : 'nrl';
		}
		$conf   = TIPC_Settings::league( $league );
		$season = (int) $conf['season'];
		$rounds = TIPC_DB::rounds( $league, $season );
		$round  = isset( $_GET['round'] ) ? (int) $_GET['round'] : TIPC_DB::current_round( $league, $season ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $rounds[ $round ] ) && $rounds ) {
			$round = (int) array_key_first( $rounds );
		}
		$games  = $rounds ? TIPC_DB::games( $league, $season, $round ) : array();
		$counts = TIPC_DB::tip_counts( wp_list_pluck( $games, 'id' ) );
		$last   = TIPC_Sync::last_full( $league, $season );
		?>
		<div class="wrap tipc-admin">
			<h1>Fixtures &amp; Results</h1>
			<?php self::admin_notices(); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $leagues as $key => $info ) : ?>
					<a class="nav-tab <?php echo $key === $league ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::games_url( array( 'league' => $key ) ) ); ?>"><?php echo esc_html( $info['name'] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<p>
				<strong><?php echo esc_html( $conf['label'] ); ?></strong> &middot; season <?php echo esc_html( $season ); ?>
				&middot; source: <?php echo esc_html( $leagues[ $league ]['providers'][ $conf['provider'] ] ); ?>
				&middot; results: <?php echo TIPC_OddsAPI::is_results_source( $league ) ? 'The Odds API' : 'same source'; ?>
				&middot; last full refresh: <?php echo esc_html( self::fmt_time( $last ) ); ?>
				<?php if ( empty( $conf['enabled'] ) ) : ?>
					&middot; <span class="tipc-pill">Off: turn it on in Settings for automatic updates</span>
				<?php endif; ?>
			</p>

			<div class="tipc-toolbar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tipc_sync_now">
					<input type="hidden" name="league" value="<?php echo esc_attr( $league ); ?>">
					<?php wp_nonce_field( 'tipc_sync_now' ); ?>
					<button class="button button-primary">Sync fixtures &amp; results now</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tipc_test_feed">
					<input type="hidden" name="league" value="<?php echo esc_attr( $league ); ?>">
					<?php wp_nonce_field( 'tipc_test_feed' ); ?>
					<button class="button">Test feed (doesn't save)</button>
				</form>
				<?php if ( TIPC_OddsAPI::is_results_source( $league ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tipc_odds_check">
						<input type="hidden" name="league" value="<?php echo esc_attr( $league ); ?>">
						<?php wp_nonce_field( 'tipc_odds_check' ); ?>
						<button class="button" name="what" value="events">Match Odds API fixtures (free)</button>
						<button class="button" name="what" value="scores">Get Odds API results now (2 credits)</button>
					</form>
				<?php endif; ?>
				<?php if ( $rounds ) : ?>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="tipc-games">
						<input type="hidden" name="league" value="<?php echo esc_attr( $league ); ?>">
						<label for="tipc-round" class="screen-reader-text">Round</label>
						<select id="tipc-round" name="round" onchange="this.form.submit()">
							<?php foreach ( $rounds as $r => $info ) : ?>
								<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $r, $round ); ?>><?php echo esc_html( $info['label'] . ' (' . $info['finals'] . '/' . $info['games'] . ' done)' ); ?></option>
							<?php endforeach; ?>
						</select>
						<noscript><button class="button">Go</button></noscript>
					</form>
				<?php endif; ?>
			</div>

			<?php self::test_result( $league ); ?>

			<?php if ( ! $rounds ) : ?>
				<p>No games loaded for this season yet. Click <strong>Sync fixtures &amp; results now</strong>.</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tipc_save_games">
					<input type="hidden" name="league" value="<?php echo esc_attr( $league ); ?>">
					<input type="hidden" name="round" value="<?php echo esc_attr( $round ); ?>">
					<?php wp_nonce_field( 'tipc_save_games' ); ?>
					<table class="widefat striped tipc-games">
						<thead><tr>
							<th>Kickoff</th><th>Home</th><th>Score</th><th>Away</th><th>Status</th><th>Tips (H/D/A)</th>
							<th title="Lock this result so the data feed can't overwrite it">Manual</th>
						</tr></thead>
						<tbody>
						<?php
						foreach ( $games as $g ) :
							$id = (int) $g->id;
							$c  = isset( $counts[ $id ] ) ? $counts[ $id ] : array();
							?>
							<tr>
								<td><?php echo esc_html( wp_date( 'D j M, g:ia', strtotime( $g->kickoff . ' UTC' ) ) ); ?><br><span class="description"><?php echo esc_html( $g->venue ); ?></span></td>
								<td><?php echo esc_html( $g->home ); ?></td>
								<td>
									<input class="small-text" type="number" name="games[<?php echo esc_attr( $id ); ?>][home_score]" value="<?php echo esc_attr( $g->home_score ); ?>" aria-label="Home score">
									–
									<input class="small-text" type="number" name="games[<?php echo esc_attr( $id ); ?>][away_score]" value="<?php echo esc_attr( $g->away_score ); ?>" aria-label="Away score">
								</td>
								<td><?php echo esc_html( $g->away ); ?></td>
								<td>
									<select name="games[<?php echo esc_attr( $id ); ?>][status]" aria-label="Status">
										<?php foreach ( array( 'scheduled', 'live', 'final', 'postponed', 'cancelled' ) as $st ) : ?>
											<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $g->status, $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><?php echo esc_html( ( isset( $c['home'] ) ? $c['home'] : 0 ) . ' / ' . ( isset( $c['draw'] ) ? $c['draw'] : 0 ) . ' / ' . ( isset( $c['away'] ) ? $c['away'] : 0 ) ); ?></td>
								<td><input type="checkbox" name="games[<?php echo esc_attr( $id ); ?>][manual]" value="1" <?php checked( (int) $g->manual, 1 ); ?> aria-label="Manual result"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">Changing a score or status here ticks <em>Manual</em> automatically, so the data feed won't overwrite your fix. Untick it to let the feed take over again.</p>
					<?php submit_button( 'Save results' ); ?>
				</form>
			<?php endif; ?>

			<?php self::log_table(); ?>

			<h2>Danger zone</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete every fixture AND every tip for <?php echo esc_js( $conf['label'] . ' ' . $season ); ?>? This cannot be undone.');">
				<input type="hidden" name="action" value="tipc_delete_season">
				<input type="hidden" name="league" value="<?php echo esc_attr( $league ); ?>">
				<?php wp_nonce_field( 'tipc_delete_season' ); ?>
				<button class="button button-link-delete">Delete all <?php echo esc_html( $conf['label'] . ' ' . $season ); ?> fixtures and tips</button>
				<p class="description">Only needed if you switch data source mid-season and end up with duplicate games.</p>
			</form>
		</div>
		<?php
	}

	private static function admin_notices() {
		$msg = get_transient( 'tipc_notice_' . get_current_user_id() );
		if ( ! $msg ) {
			return;
		}
		delete_transient( 'tipc_notice_' . get_current_user_id() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msg['type'] ), esc_html( $msg['text'] ) );
	}

	private static function set_notice( $text, $type = 'success' ) {
		set_transient(
			'tipc_notice_' . get_current_user_id(),
			array(
				'text' => $text,
				'type' => $type,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	private static function test_result( $league ) {
		$key  = 'tipc_test_' . get_current_user_id();
		$test = get_transient( $key );
		if ( ! $test || $test['league'] !== $league ) {
			return;
		}
		delete_transient( $key );
		?>
		<div class="tipc-card" style="margin:12px 0">
			<h3>Feed test: <?php echo esc_html( $test['provider'] ); ?></h3>
			<?php if ( ! $test['ok'] ) : ?>
				<p><span class="tipc-pill err">Failed</span> <?php echo esc_html( $test['message'] ); ?></p>
			<?php else : ?>
				<p><span class="tipc-pill on">Working</span>
					<?php echo esc_html( sprintf( '%d games across %d rounds, %d with final results. Took %.1fs.', $test['count'], $test['rounds'], $test['finals'], $test['secs'] ) ); ?></p>
				<table class="widefat striped tipc-sample">
					<thead><tr><th>Round</th><th>Kickoff (UTC)</th><th>Home</th><th>Away</th><th>Status</th><th>Score</th></tr></thead>
					<tbody>
					<?php foreach ( $test['sample'] as $g ) : ?>
						<tr>
							<td><?php echo esc_html( $g['round_label'] ); ?></td>
							<td><?php echo esc_html( $g['kickoff'] ); ?></td>
							<td><?php echo esc_html( $g['home'] ); ?></td>
							<td><?php echo esc_html( $g['away'] ); ?></td>
							<td><?php echo esc_html( $g['status'] ); ?></td>
							<td><?php echo esc_html( null === $g['home_score'] ? '' : $g['home_score'] . '–' . $g['away_score'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function log_table() {
		$log = get_option( TIPC_Sync::LOG_OPTION, array() );
		echo '<h2>Recent sync activity</h2>';
		if ( empty( $log ) ) {
			echo '<p>Nothing yet.</p>';
			return;
		}
		echo '<table class="widefat striped tipc-log"><thead><tr><th>When</th><th>League</th><th>Type</th><th>Result</th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 15 ) as $e ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><span class="tipc-pill %s">%s</span> %s</td></tr>',
				esc_html( self::fmt_time( $e['time'] ) ),
				esc_html( strtoupper( $e['league'] ) ),
				esc_html( 'full' === $e['mode'] ? 'Full refresh' : ( 'odds' === $e['mode'] ? 'Odds API' : 'Result check' ) ),
				$e['ok'] ? 'on' : 'err',
				$e['ok'] ? 'OK' : 'Error',
				esc_html( $e['message'] )
			);
		}
		echo '</tbody></table>';
	}

	/* ---------- Handlers ---------- */

	private static function posted_league() {
		$league = isset( $_POST['league'] ) ? sanitize_key( wp_unslash( $_POST['league'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! TIPC_Settings::is_valid_league( $league ) ) {
			wp_die( 'Unknown league.' );
		}
		return $league;
	}

	public static function handle_sync_now() {
		self::require_cap();
		check_admin_referer( 'tipc_sync_now' );
		$league = self::posted_league();
		$result = TIPC_Sync::sync_league( $league, 'full' );
		if ( is_wp_error( $result ) ) {
			self::set_notice( 'Sync failed: ' . $result->get_error_message(), 'error' );
		} else {
			if ( 0 === $result['results_changed'] ) {
				// Settings like draw rules may have changed; refresh standings anyway.
				$conf = TIPC_Settings::league( $league );
				TIPC_Scoring::flush( $league, $conf['season'] );
			}
			self::set_notice( sprintf( 'Synced: %d new games, %d updated, %d results changed.', $result['inserted'], $result['updated'], $result['results_changed'] ) );
		}
		wp_safe_redirect( self::games_url( array( 'league' => $league ) ) );
		exit;
	}

	public static function handle_test_feed() {
		self::require_cap();
		check_admin_referer( 'tipc_test_feed' );
		$league   = self::posted_league();
		$conf     = TIPC_Settings::league( $league );
		$leagues  = TIPC_Settings::leagues();
		$provider = TIPC_Provider::make( $league );
		$start    = microtime( true );
		$games    = is_wp_error( $provider ) ? $provider : $provider->fetch_season();
		$out      = array(
			'league'   => $league,
			'provider' => $leagues[ $league ]['providers'][ $conf['provider'] ] . ' · season ' . $conf['season'],
			'secs'     => microtime( true ) - $start,
		);
		if ( is_wp_error( $games ) ) {
			$out['ok']      = false;
			$out['message'] = $games->get_error_message();
		} else {
			$rounds = array();
			$finals = 0;
			foreach ( $games as $g ) {
				$rounds[ $g['round'] ] = true;
				if ( 'final' === $g['status'] ) {
					$finals++;
				}
			}
			// Show a few finished and a few upcoming games.
			$done     = array_values( array_filter( $games, function ( $g ) { return 'final' === $g['status']; } ) );
			$upcoming = array_values( array_filter( $games, function ( $g ) { return 'final' !== $g['status']; } ) );
			$sample   = array_merge( array_slice( $done, -3 ), array_slice( $upcoming, 0, 3 ) );
			if ( empty( $sample ) ) {
				$sample = array_slice( $games, 0, 6 );
			}
			$out = array_merge(
				$out,
				array(
					'ok'     => true,
					'count'  => count( $games ),
					'rounds' => count( $rounds ),
					'finals' => $finals,
					'sample' => $sample,
				)
			);
		}
		set_transient( 'tipc_test_' . get_current_user_id(), $out, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::games_url( array( 'league' => $league ) ) );
		exit;
	}

	public static function handle_save_games() {
		global $wpdb;
		self::require_cap();
		check_admin_referer( 'tipc_save_games' );
		$league  = self::posted_league();
		$round   = isset( $_POST['round'] ) ? (int) $_POST['round'] : 0;
		$posted  = isset( $_POST['games'] ) && is_array( $_POST['games'] ) ? wp_unslash( $_POST['games'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$valid   = array( 'scheduled', 'live', 'final', 'postponed', 'cancelled' );
		$changed = 0;
		$season  = 0;

		foreach ( $posted as $id => $row ) {
			$game = TIPC_DB::get_game( (int) $id );
			if ( ! $game || $game->league !== $league ) {
				continue;
			}
			$season = (int) $game->season;
			$hs     = isset( $row['home_score'] ) && '' !== trim( $row['home_score'] ) ? (int) $row['home_score'] : null;
			$as     = isset( $row['away_score'] ) && '' !== trim( $row['away_score'] ) ? (int) $row['away_score'] : null;
			$status = isset( $row['status'] ) && in_array( $row['status'], $valid, true ) ? $row['status'] : $game->status;
			$manual = empty( $row['manual'] ) ? 0 : 1;

			$result_edited = ( (string) $hs !== (string) $game->home_score ) || ( (string) $as !== (string) $game->away_score ) || $status !== $game->status;
			if ( $result_edited ) {
				$manual = 1;
			}

			$norm = TIPC_Provider::normalise(
				array(
					'ext_id'     => $game->ext_id,
					'kickoff'    => $game->kickoff,
					'home'       => $game->home,
					'away'       => $game->away,
					'status'     => $status,
					'home_score' => $hs,
					'away_score' => $as,
				)
			);

			if ( ! $result_edited && $manual === (int) $game->manual ) {
				continue;
			}

			$wpdb->update(
				TIPC_DB::games_table(),
				array(
					'status'     => $norm['status'],
					'home_score' => $norm['home_score'],
					'away_score' => $norm['away_score'],
					'winner'     => $norm['winner'],
					'manual'     => $manual,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $game->id )
			);
			$changed++;
		}

		if ( $changed && $season ) {
			TIPC_Sync::results_changed( $league, $season );
		}
		self::set_notice( $changed ? sprintf( '%d games updated. Leaderboards refreshed.', $changed ) : 'No changes.' );
		wp_safe_redirect(
			self::games_url(
				array(
					'league' => $league,
					'round'  => $round,
				)
			)
		);
		exit;
	}

	public static function handle_delete_season() {
		self::require_cap();
		check_admin_referer( 'tipc_delete_season' );
		$league = self::posted_league();
		$conf   = TIPC_Settings::league( $league );
		TIPC_DB::delete_season( $league, (int) $conf['season'] );
		TIPC_Scoring::flush( $league, (int) $conf['season'] );
		self::set_notice( 'Deleted all fixtures and tips for ' . $conf['label'] . ' ' . $conf['season'] . '.', 'warning' );
		wp_safe_redirect( self::games_url( array( 'league' => $league ) ) );
		exit;
	}

	public static function handle_odds_check() {
		self::require_cap();
		check_admin_referer( 'tipc_odds_check' );
		$league = self::posted_league();
		$what   = isset( $_POST['what'] ) && 'scores' === $_POST['what'] ? 'scores' : 'events';
		$res    = 'scores' === $what ? TIPC_OddsAPI::sync_scores( $league ) : TIPC_OddsAPI::sync_events( $league );
		if ( is_wp_error( $res ) ) {
			self::set_notice( 'Odds API: ' . $res->get_error_message(), 'error' );
		} else {
			$msg = sprintf( 'Odds API: %d games matched', $res['matched'] );
			$msg .= 'scores' === $what ? sprintf( ', %d results changed.', $res['results_changed'] ) : sprintf( ', %d kickoff times updated.', $res['kickoffs'] );
			if ( $res['unmatched'] ) {
				$msg .= ' Not matched (check team names or that the draw is loaded): ' . implode( ', ', array_slice( $res['unmatched'], 0, 8 ) ) . '.';
			}
			self::set_notice( $msg, $res['unmatched'] ? 'warning' : 'success' );
		}
		wp_safe_redirect( self::games_url( array( 'league' => $league ) ) );
		exit;
	}

	public static function handle_run_tick() {
		self::require_cap();
		check_admin_referer( 'tipc_run_tick' );
		delete_transient( 'tipc_sync_lock' );
		$summary = TIPC_Sync::tick();
		$parts   = array();
		foreach ( $summary as $league => $res ) {
			if ( is_array( $res ) ) {
				$res = sprintf( '%d new, %d updated, %d results', $res['inserted'], $res['updated'], $res['results_changed'] );
			}
			$parts[] = strtoupper( $league ) . ': ' . $res;
		}
		self::set_notice( $parts ? 'Check complete. ' . implode( ' · ', $parts ) : 'No competitions are switched on.' );
		wp_safe_redirect( self::games_url() );
		exit;
	}
}
