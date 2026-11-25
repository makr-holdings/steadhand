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
 * filters. When STEADHAND_DEV_UPDATES is true, the token is never read and never sent, even if it is also defined.
 * Steadhand never stores, logs or displays the token. With neither constant, nothing is hooked, loaded or printed.
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
	// The version in the tag and in the zip name must be the same (\1). \z, not $, as above.
	public const PUBLIC_DOWNLOAD_PATTERN = '/^https:\/\/github\.com\/makr-holdings\/steadhand\/releases\/download\/v(\d+\.\d+\.\d+)\/steadhand-dev-\1\.zip\z/';

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
	 * Whether a download URL may be offered, for the mode the checker runs in.
	 *
	 * With a token, the URL must be an API asset URL of this repository. Without one, it must be the public
	 * browser download of a dev zip whose version in the tag matches the version in the zip name, and when $version
	 * is given as a string it must equal that version too. Without one, a missing, null or non-string $version
	 * is refused.
	 *
	 * @param mixed $url       The download URL.
	 * @param bool  $tokenless True for the tokenless checker, false for the checker with a token.
	 * @param mixed $version   The version the update reports, or null when it has none.
	 * @return bool
	 */
	public static function is_allowed_download( mixed $url, bool $tokenless, mixed $version = null ): bool {
		if ( ! is_string( $url ) ) {
			return false;
		}
		if ( ! $tokenless ) {
			return str_starts_with( $url, self::ASSET_URL_PREFIX );
		}
		if ( 1 !== preg_match( self::PUBLIC_DOWNLOAD_PATTERN, $url, $matches ) ) {
			return false;
		}
		if ( ! is_string( $version ) ) {
			return false;
		}
		return $version === $matches[1];
	}

	/**
	 * Start the update checker when the opt-in constant is true or a token is configured, and the library is installed.
	 * Otherwise do nothing.
	 *
	 * When the opt-in is true, the checker is always tokenless: the token constant is not read into the checker and is
	 * never sent, even when it is also defined. Token mode is used only when the opt-in is not true and the token is
	 * non-empty.
	 *
	 * @return void
	 */
	public static function register(): void {
		$opted_in = self::opted_in();
		$token    = $opted_in ? '' : self::token();
		if ( ! $opted_in && '' === $token ) {
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
	 * An update cached by an earlier check is dropped when the mode this checker runs in would not accept its download.
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
				return self::is_allowed_download( $info->download_url, $tokenless, $info->version ?? null ) ? $info : null;
			},
			PHP_INT_MAX,
			1
		);

		$checker->getVcsApi()->enableReleaseAssets(
			self::ASSET_PATTERN,
			\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
		);

		// The library caches the offered update and serves it later without running request_info_result again, so an
		// update cached under another mode (or a bad one) would still be offered. Drop it here, and only then write.
		$state  = $checker->getUpdateState();
		$cached = $state->getUpdate();
		if ( null !== $cached && ! self::is_allowed_download( $cached->download_url ?? null, $tokenless, $cached->version ?? null ) ) {
			$state->setUpdate( null )->save();
		}

		return $checker;
	}
}

Updater::register();
