<?php
/**
 * Updates from GitHub releases, via the Plugin Update Checker library
 * (https://github.com/YahnisElsts/plugin-update-checker, MIT licence).
 *
 * Each release on the repository carries a tipping-comp-x.y.z.zip asset.
 * WordPress then shows "update available" for Tipping Comp like any other
 * plugin, and the normal "Enable auto-updates" link on the Plugins screen
 * makes it update itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIPC_Updater {

	/** Default repository (owner/repo). Can be overridden in Settings. */
	const DEFAULT_REPO = 'FrontRowMedia32/tipping-comp';

	public static function repo() {
		$repo = trim( (string) TIPC_Settings::get( 'update_repo', '' ) );
		if ( '' === $repo ) {
			$repo = self::DEFAULT_REPO;
		}
		$repo = preg_replace( '#^https?://github\.com/#i', '', $repo );
		$repo = trim( $repo, "/ \t" );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	/** @var object|null The update checker, once built. */
	private static $checker = null;

	public static function checker() {
		return self::$checker;
	}

	public static function init() {
		$repo = self::repo();
		if ( '' === $repo || null !== self::$checker ) {
			return; // Not configured, or already set up (the library allows one per plugin).
		}
		require_once TIPC_DIR . 'vendor/plugin-update-checker/load-v5p7.php';

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/' . $repo . '/',
			TIPC_FILE,
			'tipping-comp'
		);
		self::$checker = $checker;
		$api           = $checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/tipping-comp-[0-9.]+\.zip$/i' );
		}
		$token = trim( (string) TIPC_Settings::api_key( 'github' ) );
		if ( '' !== $token ) {
			$checker->setAuthentication( $token ); // Only needed for a private repository.
		}
	}
}
