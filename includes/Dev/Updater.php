<?php
/**
 * Dev-only update checker: updates Steadhand from this repository's private GitHub releases.
 *
 * This file and the whole includes/Dev/ directory (including its vendor/ library) are excluded from the
 * WordPress.org build. Nothing else may reference this class; steadhand.php loads it with one guarded require.
 *
 * The token is read only from the STEADHAND_DEV_GITHUB_TOKEN constant. Steadhand never stores, logs or displays it.
 * The update library sends it as an Authorization header on requests to this repository's GitHub API endpoints,
 * where other code on the site can observe it through the library's and WordPress's HTTP filters.
 * With no token, nothing is hooked, loaded or printed.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Dev;

defined( 'ABSPATH' ) || exit;

/**
 * Wires Plugin Update Checker to the private repository's dev release zips.
 */
final class Updater {

	public const TOKEN_CONSTANT = 'STEADHAND_DEV_GITHUB_TOKEN';
	public const REPOSITORY     = 'https://github.com/makr-holdings/steadhand/';
	// \z, not $: a plain $ also matches before a trailing newline.
	public const ASSET_PATTERN = '/^steadhand-dev-\d+\.\d+\.\d+\.zip\z/';
	// The only download URLs the updater may offer: the dev zip asset of this repository's releases.
	public const ASSET_URL_PREFIX = 'https://api.github.com/repos/makr-holdings/steadhand/releases/assets/';

	/**
	 * The token from the constant, or '' when the constant is not defined or is not a string.
	 *
	 * @return string
	 */
	public static function token(): string {
		if ( ! defined( self::TOKEN_CONSTANT ) ) {
			return '';
		}
		$token = constant( self::TOKEN_CONSTANT );
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Start the update checker when a token is configured and the library is installed. Otherwise do nothing.
	 *
	 * @return void
	 */
	public static function register(): void {
		$token = self::token();
		if ( '' === $token ) {
			return;
		}

		$autoload = __DIR__ . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return;
		}
		require_once $autoload;

		self::create_checker( $token );
	}

	/**
	 * Build the checker for the Steadhand slug: private authentication, and the dev zip as the only release asset.
	 *
	 * @param string $token GitHub token (fine-grained, Contents read-only).
	 * @return object|null The checker, or null when the token is empty or the library is not loaded.
	 */
	public static function create_checker( #[\SensitiveParameter] string $token ): ?object {
		if ( '' === $token || ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
			return null;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPOSITORY,
			STEADHAND_FILE,
			'steadhand'
		);

		// Private repositories: the library sends the token as an Authorization header on API and asset requests.
		$checker->setAuthentication( $token );

		// REQUIRE_RELEASE_ASSETS alone is not enough. It only makes the latest-release strategy return null when no
		// asset matches; the library then tries the latest tag and the branch, which return GitHub's source zipball
		// (no vendor/ directory). So the strategies are limited to the latest release here ...
		add_filter(
			$checker->getUniqueName( 'vcs_update_detection_strategies' ),
			static function ( array $strategies ): array {
				return array_intersect_key( $strategies, array( \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_LATEST_RELEASE => true ) );
			},
			PHP_INT_MAX
		);

		// ... and any offered download must be a dev zip asset of this repository's releases, as a second line of defence.
		add_filter(
			$checker->getUniqueName( 'request_info_result' ),
			static function ( $info ) {
				if ( ! is_object( $info ) || ! isset( $info->download_url ) || ! is_string( $info->download_url ) ) {
					return null;
				}
				return str_starts_with( $info->download_url, self::ASSET_URL_PREFIX ) ? $info : null;
			},
			PHP_INT_MAX,
			1
		);

		$checker->getVcsApi()->enableReleaseAssets(
			self::ASSET_PATTERN,
			\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
		);

		return $checker;
	}
}

Updater::register();
