<?php
/**
 * Dev-only update checker: updates Steadhand from this repository's GitHub releases (the dev zip asset).
 *
 * This file and the whole includes/Dev/ directory (including its vendor/ library) are excluded from the
 * WordPress.org build. Nothing else may reference this class; steadhand.php loads it with one guarded require.
 *
 * The repository is public, so no token is needed. The updater is turned on by the constant
 * STEADHAND_DEV_UPDATES set to boolean true. The constant STEADHAND_DEV_GITHUB_TOKEN is optional: when it is set
 * to a non-empty string, the update library sends it as an Authorization header on requests to this repository's
 * GitHub API endpoints, where other code on the site can observe it through the library's and WordPress's HTTP
 * filters. Steadhand never stores, logs or displays the token. With neither constant, nothing is hooked, loaded
 * or printed.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Dev;

defined( 'ABSPATH' ) || exit;

/**
 * Wires Plugin Update Checker to this repository's dev release zips.
 */
final class Updater {

	public const OPT_IN_CONSTANT = 'STEADHAND_DEV_UPDATES';
	public const TOKEN_CONSTANT  = 'STEADHAND_DEV_GITHUB_TOKEN';
	public const REPOSITORY      = 'https://github.com/makr-holdings/steadhand/';
	// \z, not $: a plain $ also matches before a trailing newline.
	public const ASSET_PATTERN = '/^steadhand-dev-\d+\.\d+\.\d+\.zip\z/';
	// With a token: the only download URLs the updater may offer are the dev zip assets of this repository's releases, via the API.
	public const ASSET_URL_PREFIX = 'https://api.github.com/repos/makr-holdings/steadhand/releases/assets/';
	// Without a token: the only download URLs the updater may offer are the public dev zip downloads of this repository's releases.
	public const PUBLIC_DOWNLOAD_PATTERN = '/^https:\/\/github\.com\/makr-holdings\/steadhand\/releases\/download\/v\d+\.\d+\.\d+\/steadhand-dev-\d+\.\d+\.\d+\.zip\z/';

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
	 * Whether the opt-in constant is set to boolean true. Only the exact value true counts.
	 *
	 * @return bool
	 */
	public static function opted_in(): bool {
		if ( ! defined( self::OPT_IN_CONSTANT ) ) {
			return false;
		}
		return self::is_opt_in( constant( self::OPT_IN_CONSTANT ) );
	}

	/**
	 * Whether a value of the opt-in constant turns the updater on. Strict: 1, '1', 'true', 'yes' and the like do not.
	 *
	 * @param mixed $value The constant's value.
	 * @return bool
	 */
	public static function is_opt_in( mixed $value ): bool {
		return true === $value;
	}

	/**
	 * Start the update checker when a token is configured or the opt-in constant is true, and the library is installed.
	 * Otherwise do nothing.
	 *
	 * @return void
	 */
	public static function register(): void {
		$token = self::token();
		if ( '' === $token && ! self::opted_in() ) {
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
	 * Build the checker for the Steadhand slug, with the dev zip as the only release asset.
	 *
	 * An empty token builds a tokenless checker for the public repository: no Authorization header is ever sent.
	 * A non-empty token builds a checker that authenticates to the private-repository API as before.
	 *
	 * @param string $token GitHub token (fine-grained, Contents read-only), or '' for no token.
	 * @return object|null The checker, or null when the library is not loaded.
	 */
	public static function create_checker( #[\SensitiveParameter] string $token = '' ): ?object {
		if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
			return null;
		}

		$tokenless = '' === $token;

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPOSITORY,
			STEADHAND_FILE,
			'steadhand'
		);

		// Only with a token: the library then sends it as an Authorization header on API and asset requests.
		if ( ! $tokenless ) {
			$checker->setAuthentication( $token );
		}

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

		// ... and any offered download must be a dev zip asset of this repository's releases, in the shape of this
		// mode (API asset URL with a token, public download URL without one), as a second line of defence.
		add_filter(
			$checker->getUniqueName( 'request_info_result' ),
			static function ( $info ) use ( $tokenless ) {
				if ( ! is_object( $info ) || ! isset( $info->download_url ) || ! is_string( $info->download_url ) ) {
					return null;
				}
				$allowed = $tokenless
					? 1 === preg_match( self::PUBLIC_DOWNLOAD_PATTERN, $info->download_url )
					: str_starts_with( $info->download_url, self::ASSET_URL_PREFIX );
				return $allowed ? $info : null;
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
