<?php
/**
 * Tests for the dev-only updater in includes/Dev.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Dev\Updater;
use WP_Hook;

/**
 * The updater is inert unless STEADHAND_DEV_UPDATES is true or STEADHAND_DEV_GITHUB_TOKEN is set. The test environment
 * defines neither constant, so these tests cover the inert path directly, and build checkers directly: a tokenless
 * checker (the public repository) and a checker with a dummy token.
 */
final class DevUpdaterTest extends TestCase {

	private const DUMMY_TOKEN = 'dummy-token-value';

	private const API_PATH_PREFIX  = '/repos/makr-holdings/steadhand/';
	private const ASSET_URL_PREFIX = 'https://api.github.com/repos/makr-holdings/steadhand/releases/assets/';
	private const ZIPBALL_URL      = 'https://api.github.com/repos/makr-holdings/steadhand/zipball/v9.9.9';

	/**
	 * Every HTTP request the test made: URL, lower-cased request headers, and whether a mock answered it.
	 *
	 * @var array<int, array{url: string, headers: array<string, string>, mocked: bool}>
	 */
	private array $http_log = array();

	/**
	 * Mocked GitHub API responses keyed by URL path: [ HTTP status, JSON body ].
	 *
	 * @var array<string, array{0: int, 1: mixed}>
	 */
	private array $http_routes = array();

	protected function setUp(): void {
		parent::setUp();
		$this->http_log    = array();
		$this->http_routes = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http_request' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http_request' ), 10 );
		parent::tearDown();
	}

	/**
	 * Answers mocked GitHub API paths. Anything else gets a WP_Error and is recorded, so no request can reach the network.
	 *
	 * @internal
	 * @param mixed  $preempt Value from earlier filters.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array|\WP_Error
	 */
	public function fake_http_request( $preempt, $args, $url ) {
		unset( $preempt );
		$headers = is_array( $args ) && isset( $args['headers'] ) ? $args['headers'] : array();
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		$lower_headers = array();
		foreach ( (array) $headers as $name => $value ) {
			$lower_headers[ strtolower( (string) $name ) ] = (string) $value;
		}

		$index                    = count( $this->http_log );
		$this->http_log[ $index ] = array(
			'url'     => (string) $url,
			'headers' => $lower_headers,
			'mocked'  => false,
		);

		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( 'api.github.com' === $host && isset( $this->http_routes[ $path ] ) ) {
			list( $code, $body )                = $this->http_routes[ $path ];
			$this->http_log[ $index ]['mocked'] = true;
			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode( $body ),
				'response' => array(
					'code'    => $code,
					'message' => (string) $code,
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		return new \WP_Error( 'steadhand_unmocked_http', 'Unmocked HTTP request in a test: ' . $url );
	}

	/**
	 * Mocks the GitHub endpoints the update check reads. The latest release is v9.9.9 with the given assets;
	 * the tag list and branch also offer v9.9.9, with GitHub's source zipball, so a fallback would be visible.
	 * The library also asks for readme.txt, because the installed plugin ships one; it is read from this repository at the release's tag.
	 *
	 * @param array<int, array<string, mixed>> $assets Release assets (name, url).
	 */
	private function mock_github_release( array $assets ): void {
		$release = array(
			'tag_name'    => 'v9.9.9',
			'name'        => 'v9.9.9',
			'draft'       => false,
			'prerelease'  => false,
			'created_at'  => '2026-11-01T00:00:00Z',
			'body'        => '',
			'zipball_url' => self::ZIPBALL_URL,
			'assets'      => array_map(
				static fn( array $asset ): array => $asset + array(
					'browser_download_url' => 'https://github.com/makr-holdings/steadhand/releases/download/v9.9.9/' . $asset['name'],
					'download_count'       => 0,
				),
				$assets
			),
		);
		$commit  = array(
			'sha'    => str_repeat( 'a', 40 ),
			'commit' => array( 'author' => array( 'date' => '2026-11-01T00:00:00Z' ) ),
		);

		$this->http_routes = array(
			self::API_PATH_PREFIX . 'releases/latest'     => array( 200, $release ),
			self::API_PATH_PREFIX . 'releases'            => array( 200, array( $release ) ),
			self::API_PATH_PREFIX . 'tags'                => array(
				200,
				array(
					array(
						'name'        => 'v9.9.9',
						'zipball_url' => self::ZIPBALL_URL,
						'commit'      => array( 'sha' => str_repeat( 'a', 40 ) ),
					),
				),
			),
			self::API_PATH_PREFIX . 'branches/master'     => array(
				200,
				array(
					'name'   => 'master',
					'commit' => $commit,
				),
			),
			self::API_PATH_PREFIX . 'commits'             => array( 200, array( $commit ) ),
			self::API_PATH_PREFIX . 'contents/steadhand.php' => array( 404, array( 'message' => 'Not Found' ) ),
			self::API_PATH_PREFIX . 'contents/CHANGELOG.md' => array( 404, array( 'message' => 'Not Found' ) ),
			self::API_PATH_PREFIX . 'contents/readme.txt' => array( 404, array( 'message' => 'Not Found' ) ),
		);
	}

	/**
	 * Fails the test if any request was not answered by a mock.
	 */
	private function assert_no_unmocked_http_requests(): void {
		$unmocked = array_values(
			array_map(
				static fn( array $request ): string => $request['url'],
				array_filter( $this->http_log, static fn( array $request ): bool => ! $request['mocked'] )
			)
		);
		$this->assertSame( array(), $unmocked, 'The test made HTTP requests that were not mocked.' );
	}

	/**
	 * Fails the test if a zipball URL was returned as an update, or if the update check was checked against a source zipball.
	 *
	 * @param object|null $update Update returned by the library.
	 */
	private function assert_no_zipball_offered( ?object $update ): void {
		if ( null !== $update ) {
			$this->assertStringNotContainsString( '/zipball/', (string) $update->download_url, 'A source zipball was offered as an update.' );
		}
	}

	public function test_a_tag_without_a_dev_zip_is_never_offered_as_an_update(): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => 'steadhand-9.9.9.zip',
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );

		$update = $checker->checkForUpdates();

		$this->assert_no_zipball_offered( $update );
		$this->assertNull( $update, 'A release without a steadhand-dev zip must not be offered as an update.' );
		$this->assert_only_latest_release_consulted();
		$this->assert_no_unmocked_http_requests();
	}

	public function test_token_is_empty_when_the_constant_is_not_defined(): void {
		$this->assertFalse( defined( Updater::TOKEN_CONSTANT ), 'The test environment must not define ' . Updater::TOKEN_CONSTANT . '.' );
		$this->assertSame( '', Updater::token() );
	}

	public function test_register_without_a_token_or_opt_in_adds_no_hooks_and_prints_nothing(): void {
		$this->assertFalse( defined( Updater::OPT_IN_CONSTANT ), 'The test environment must not define ' . Updater::OPT_IN_CONSTANT . '.' );
		$hooks_before = $this->hook_counts();

		ob_start();
		Updater::register();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertSame( $hooks_before, $this->hook_counts() );
	}

	public function test_create_checker_with_an_empty_token_builds_a_tokenless_checker(): void {
		$this->load_library();

		$checker = Updater::create_checker( '' );

		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );
		$this->assertSame( 'steadhand', $checker->slug );
		$this->assertFalse( $checker->getVcsApi()->isAuthenticationEnabled(), 'A tokenless checker must not enable authentication.' );
	}

	public function test_tokenless_checker_is_for_the_steadhand_slug_with_dev_zip_assets_and_no_authentication(): void {
		$this->load_library();

		$checker = Updater::create_checker( '' );

		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );
		$this->assertSame( 'steadhand', $checker->slug );

		$api = $checker->getVcsApi();
		$this->assertFalse( $api->isAuthenticationEnabled(), 'A tokenless checker must not enable authentication.' );

		$assets_enabled = new \ReflectionProperty( $api, 'releaseAssetsEnabled' );
		$assets_enabled->setAccessible( true );
		$this->assertTrue( $assets_enabled->getValue( $api ), 'Release assets are not enabled on the tokenless checker.' );

		$asset_filter = new \ReflectionProperty( $api, 'assetFilterRegex' );
		$asset_filter->setAccessible( true );
		$this->assertSame( Updater::ASSET_PATTERN, $asset_filter->getValue( $api ) );

		$preference = new \ReflectionProperty( $api, 'releaseAssetPreference' );
		$preference->setAccessible( true );
		$this->assertSame(
			\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS,
			$preference->getValue( $api ),
			'The tokenless checker must require release assets.'
		);
	}

	public function test_opted_in_is_false_when_the_constant_is_not_defined(): void {
		$this->assertFalse( defined( Updater::OPT_IN_CONSTANT ), 'The test environment must not define ' . Updater::OPT_IN_CONSTANT . '.' );
		$this->assertFalse( Updater::opted_in() );
	}

	/**
	 * Only boolean true turns the updater on. Every other value, including values that are truthy in PHP, is off.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function values_that_do_not_opt_in(): array {
		return array(
			'int 1'       => array( 1 ),
			'string 1'    => array( '1' ),
			'string true' => array( 'true' ),
			'string yes'  => array( 'yes' ),
			'string on'   => array( 'on' ),
			'empty array' => array( array() ),
			'null'        => array( null ),
			'false'       => array( false ),
			'int 0'       => array( 0 ),
			'object'      => array( new \stdClass() ),
		);
	}

	/**
	 * A value other than boolean true does not turn the updater on.
	 *
	 * @dataProvider values_that_do_not_opt_in
	 * @param mixed $value The constant's value.
	 */
	public function test_is_opt_in_is_false_for_anything_but_boolean_true( mixed $value ): void {
		$this->assertFalse( Updater::is_opt_in( $value ) );
	}

	public function test_is_opt_in_is_true_for_boolean_true(): void {
		$this->assertTrue( Updater::is_opt_in( true ) );
	}

	/**
	 * The opt-in comes only from the constant. Filters and options with plausible names must not turn it on.
	 */
	public function test_opt_in_ignores_filters_and_options(): void {
		$this->assertFalse( defined( Updater::OPT_IN_CONSTANT ), 'The test environment must not define ' . Updater::OPT_IN_CONSTANT . '.' );

		$names = array(
			'steadhand_dev_updates',
			'steadhand_dev_update',
			'steadhand_dev_updater_enabled',
			'steadhand_dev_opt_in',
			'steadhand_updates',
			'steadhand_dev_updates_enabled',
			'puc_updates',
		);
		foreach ( $names as $name ) {
			add_filter( $name, static fn() => true );
			update_option( $name, true );
		}

		$this->assertFalse( Updater::opted_in() );
	}

	public function test_checker_is_for_the_steadhand_slug_with_authentication_and_dev_zip_assets(): void {
		$this->load_library();

		$checker = Updater::create_checker( self::DUMMY_TOKEN );

		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );
		$this->assertSame( 'steadhand', $checker->slug );

		$api = $checker->getVcsApi();
		$this->assertTrue( $api->isAuthenticationEnabled(), 'Private-repository authentication is not set on the checker.' );

		// Release-asset settings are protected in the library; reflection is the only way to read them in v5.7.
		$assets_enabled = new \ReflectionProperty( $api, 'releaseAssetsEnabled' );
		$assets_enabled->setAccessible( true );
		$this->assertTrue( $assets_enabled->getValue( $api ), 'Release assets are not enabled on the checker.' );

		$asset_filter = new \ReflectionProperty( $api, 'assetFilterRegex' );
		$asset_filter->setAccessible( true );
		$this->assertSame( Updater::ASSET_PATTERN, $asset_filter->getValue( $api ) );
	}

	public function test_release_assets_are_required_so_the_source_zipball_is_never_offered(): void {
		$this->load_library();

		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );

		// Without this preference the library falls back to GitHub's source zipball, which has no vendor/ directory.
		$api        = $checker->getVcsApi();
		$preference = new \ReflectionProperty( $api, 'releaseAssetPreference' );
		$preference->setAccessible( true );
		$this->assertSame(
			\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS,
			$preference->getValue( $api ),
			'The updater must require release assets; a release without a dev zip must not fall back to the source zipball.'
		);
	}

	public function test_a_release_with_a_dev_zip_is_offered_from_the_assets_url(): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => 'steadhand-dev-9.9.9.zip',
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );

		$update = $checker->checkForUpdates();

		$this->assertNotNull( $update, 'A release with a steadhand-dev zip was not offered as an update.' );
		$this->assertSame( '9.9.9', $update->version );
		$this->assertSame( self::ASSET_URL_PREFIX . '123', $update->download_url );
		$this->assert_only_latest_release_consulted();
		$this->assert_no_unmocked_http_requests();
	}

	/**
	 * Names that merely contain the dev zip name must never select a release asset.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function look_alike_asset_names(): array {
		return array(
			'extra extension'  => array( 'steadhand-dev-9.9.9.zip.exe' ),
			'extra prefix'     => array( 'xsteadhand-dev-9.9.9.zip' ),
			'trailing newline' => array( "steadhand-dev-9.9.9.zip\n" ),
		);
	}

	/**
	 * A look-alike asset name is never selected, so the check offers no update and never falls back.
	 *
	 * @dataProvider look_alike_asset_names
	 * @param string $name Asset name in the mocked latest release.
	 */
	public function test_look_alike_asset_names_are_not_offered( string $name ): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => $name,
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );

		$update = $checker->checkForUpdates();

		$this->assert_no_zipball_offered( $update );
		$this->assertNull( $update, 'The look-alike asset name was accepted as the dev zip.' );
		$this->assert_only_latest_release_consulted();
		$this->assert_no_unmocked_http_requests();
	}

	public function test_only_the_latest_release_strategy_is_kept(): void {
		$this->load_library();
		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );

		$strategy_hook = $checker->getUniqueName( 'vcs_update_detection_strategies' );
		$strategies    = apply_filters(
			$strategy_hook, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The library's prefixed hook name for this checker.
			array(
				\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_LATEST_RELEASE => static fn() => null,
				\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_LATEST_TAG     => static fn() => null,
				\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_BRANCH         => static fn() => null,
			),
			'steadhand'
		);

		$this->assertSame( array( \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_LATEST_RELEASE ), array_keys( $strategies ) );
	}

	public function test_request_info_results_outside_the_assets_url_are_dropped(): void {
		$this->load_library();
		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );

		// Applied directly, so this checks the request-info filter on its own, whatever the strategy filter does.
		$info_hook = $checker->getUniqueName( 'request_info_result' );

		$zipball               = new \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo();
		$zipball->download_url = self::ZIPBALL_URL;
		$this->assertNull(
			apply_filters( $info_hook, $zipball, null ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The library's prefixed hook name for this checker.
			'A download outside the assets URL prefix was not dropped.'
		);

		$asset               = new \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo();
		$asset->download_url = self::ASSET_URL_PREFIX . '123';
		$this->assertSame(
			$asset,
			apply_filters( $info_hook, $asset, null ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The library's prefixed hook name for this checker.
			'A download under the assets URL prefix was dropped.'
		);
	}

	public function test_tokenless_checker_offers_a_release_from_the_public_download_url_without_authorization(): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => 'steadhand-dev-9.9.9.zip',
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( '' );
		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );

		$update = $checker->checkForUpdates();

		$this->assertNotNull( $update, 'A release with a steadhand-dev zip was not offered to the tokenless checker.' );
		$this->assertSame( '9.9.9', $update->version );
		$this->assertSame(
			'https://github.com/makr-holdings/steadhand/releases/download/v9.9.9/steadhand-dev-9.9.9.zip',
			$update->download_url,
			'The tokenless checker must offer the public browser_download_url, not the API asset URL.'
		);
		$this->assert_no_zipball_offered( $update );
		$this->assert_only_latest_release_consulted();
		$this->assert_no_unmocked_http_requests();
		$this->assertNotEmpty( $this->http_log, 'The update check made no requests, so this test proves nothing.' );
		$this->assertSame( array(), $this->requests_with_authorization(), 'The tokenless update check sent an Authorization header.' );
	}

	public function test_tokenless_release_without_a_dev_zip_is_never_offered(): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => 'steadhand-9.9.9.zip',
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( '' );
		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );

		$update = $checker->checkForUpdates();

		$this->assert_no_zipball_offered( $update );
		$this->assertNull( $update, 'A release without a steadhand-dev zip must not be offered to the tokenless checker.' );
		$this->assert_only_latest_release_consulted();
		$this->assert_no_unmocked_http_requests();
		$this->assertSame( array(), $this->requests_with_authorization(), 'The tokenless update check sent an Authorization header.' );
	}

	/**
	 * A look-alike asset name is never selected by the tokenless checker either.
	 *
	 * @dataProvider look_alike_asset_names
	 * @param string $name Asset name in the mocked latest release.
	 */
	public function test_look_alike_asset_names_are_not_offered_without_a_token( string $name ): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => $name,
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( '' );
		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );

		$update = $checker->checkForUpdates();

		$this->assert_no_zipball_offered( $update );
		$this->assertNull( $update, 'The look-alike asset name was accepted by the tokenless checker.' );
		$this->assert_only_latest_release_consulted();
		$this->assert_no_unmocked_http_requests();
	}

	/**
	 * Download URLs the tokenless checker's request-info filter must drop.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function tokenless_download_urls_that_are_dropped(): array {
		$public = 'https://github.com/makr-holdings/steadhand/releases/download/v1.2.3/steadhand-dev-1.2.3.zip';
		return array(
			'API asset URL'      => array( self::ASSET_URL_PREFIX . '123' ),
			'http, not https'    => array( 'http://github.com/makr-holdings/steadhand/releases/download/v1.2.3/steadhand-dev-1.2.3.zip' ),
			'another repository' => array( 'https://github.com/evil/steadhand/releases/download/v1.2.3/steadhand-dev-1.2.3.zip' ),
			'look-alike host'    => array( 'https://github.com.evil.example/makr-holdings/steadhand/releases/download/v1.2.3/steadhand-dev-1.2.3.zip' ),
			'wporg zip name'     => array( 'https://github.com/makr-holdings/steadhand/releases/download/v1.2.3/steadhand-1.2.3.zip' ),
			'zipball URL'        => array( self::ZIPBALL_URL ),
			'trailing newline'   => array( $public . "\n" ),
			'query string'       => array( $public . '?download=1' ),
			'path after the zip' => array( $public . '/extra' ),
		);
	}

	/**
	 * A download URL outside the public pattern is dropped by the tokenless filter.
	 *
	 * @dataProvider tokenless_download_urls_that_are_dropped
	 * @param string $url Download URL offered to the filter.
	 */
	public function test_tokenless_request_info_filter_drops_download_urls_outside_the_public_pattern( string $url ): void {
		$this->load_library();
		$checker = Updater::create_checker( '' );
		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );

		$info               = new \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo();
		$info->download_url = $url;

		$this->assertNull( $this->apply_request_info_filter( $checker, $info ), 'A tokenless download outside the public pattern was not dropped: ' . $url );
	}

	public function test_tokenless_request_info_filter_keeps_the_public_download_url(): void {
		$this->load_library();
		$checker = Updater::create_checker( '' );
		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );

		$info               = new \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo();
		$info->download_url = 'https://github.com/makr-holdings/steadhand/releases/download/v1.2.3/steadhand-dev-1.2.3.zip';

		$this->assertSame( $info, $this->apply_request_info_filter( $checker, $info ), 'A correct public download URL was dropped.' );
	}

	public function test_tokenless_request_info_filter_drops_malformed_info(): void {
		$this->load_library();
		$checker = Updater::create_checker( '' );
		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );

		$null_url               = new \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo();
		$null_url->download_url = null;
		$int_url                = new \stdClass();
		$int_url->download_url  = 42;

		$malformed = array(
			'download_url is null'    => $null_url,
			'download_url is missing' => new \stdClass(),
			'download_url is an int'  => $int_url,
			'info is a string'        => 'https://github.com/makr-holdings/steadhand/releases/download/v1.2.3/steadhand-dev-1.2.3.zip',
			'info is null'            => null,
		);
		foreach ( $malformed as $case => $info ) {
			$this->assertNull( $this->apply_request_info_filter( $checker, $info ), 'Malformed download info was not dropped: ' . $case );
		}
	}

	/**
	 * With a token, the request-info filter keeps only the API asset URL, so the github.com download URL is dropped.
	 */
	public function test_token_request_info_filter_drops_the_github_download_url_and_keeps_the_api_asset(): void {
		$this->load_library();
		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );

		$public               = new \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo();
		$public->download_url = 'https://github.com/makr-holdings/steadhand/releases/download/v1.2.3/steadhand-dev-1.2.3.zip';
		$this->assertNull( $this->apply_request_info_filter( $checker, $public ), 'With a token, the github.com download URL was not dropped.' );

		$asset               = new \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo();
		$asset->download_url = self::ASSET_URL_PREFIX . '123';
		$this->assertSame( $asset, $this->apply_request_info_filter( $checker, $asset ), 'With a token, the API asset URL was dropped.' );
	}

	/**
	 * A tokenless update check stores neither the opt-in constant nor a token, nor any Basic credential.
	 */
	public function test_tokenless_check_stores_neither_the_opt_in_nor_a_token_in_the_database(): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => 'steadhand-dev-9.9.9.zip',
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( '' );
		$this->assertNotNull( $checker, 'create_checker() returned null without a token; the library class may be unavailable.' );
		$checker->checkForUpdates();
		$this->assertSame( array(), $this->requests_with_authorization(), 'The tokenless update check sent an Authorization header.' );

		// The state option is written by the check, so the save path ran.
		$this->assertNotFalse( get_option( 'external_updates-steadhand', false ), 'The update check did not save its state.' );

		global $wpdb;
		$needles = array( Updater::OPT_IN_CONSTANT, Updater::TOKEN_CONSTANT, 'Basic ' );
		foreach ( $needles as $needle ) {
			$like  = '%' . $wpdb->esc_like( $needle ) . '%';
			$found = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw check of the options table; caching would hide a write.
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s",
					$like,
					$like
				)
			);
			$this->assertSame( 0, $found, 'The text "' . $needle . '" was found in the options table.' );
		}
	}

	/**
	 * D7: the token must not end up in wp_options, neither raw nor as the Basic credential the library builds.
	 */
	public function test_token_is_never_stored_in_the_database(): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => 'steadhand-dev-9.9.9.zip',
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );
		$checker->checkForUpdates();
		$this->assertNotEmpty( $this->requests_with_authorization(), 'The update check sent no Authorization header, so this test proves nothing.' );

		// The state option is written by the check, so the save path ran.
		$this->assertNotFalse( get_option( 'external_updates-steadhand', false ), 'The update check did not save its state.' );

		global $wpdb;
		$needles = array( self::DUMMY_TOKEN, base64_encode( 'makr-holdings:' . self::DUMMY_TOKEN ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Rebuilds the Basic credential the library sends, to check that it is never stored or put in a URL.
		foreach ( $needles as $needle ) {
			$like  = '%' . $wpdb->esc_like( $needle ) . '%';
			$found = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw check of the options table for the token; caching would hide a write.
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s",
					$like,
					$like
				)
			);
			$this->assertSame( 0, $found, 'The dev token, or its Basic credential, was found in the options table.' );
		}
	}

	/**
	 * D7: the token is sent only to api.github.com for this repository, and never in a URL.
	 */
	public function test_the_token_is_sent_only_to_this_repositorys_github_api(): void {
		$this->load_library();
		$this->mock_github_release(
			array(
				array(
					'name' => 'steadhand-dev-9.9.9.zip',
					'url'  => self::ASSET_URL_PREFIX . '123',
				),
			)
		);

		$checker = Updater::create_checker( self::DUMMY_TOKEN );
		$this->assertNotNull( $checker, 'create_checker() returned null with a token; the library class may be unavailable.' );
		$checker->checkForUpdates();

		$authorized = $this->requests_with_authorization();
		$this->assertNotEmpty( $authorized, 'The update check sent no Authorization header, so this test proves nothing.' );
		foreach ( $authorized as $request ) {
			$this->assertSame( 'api.github.com', wp_parse_url( $request['url'], PHP_URL_HOST ), 'The token was sent to another host: ' . $request['url'] );
			$this->assertStringStartsWith( self::API_PATH_PREFIX, (string) wp_parse_url( $request['url'], PHP_URL_PATH ), 'The token was sent outside this repository: ' . $request['url'] );
		}

		$needles = array( self::DUMMY_TOKEN, base64_encode( 'makr-holdings:' . self::DUMMY_TOKEN ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Rebuilds the Basic credential the library sends, to check that it is never stored or put in a URL.
		foreach ( $this->http_log as $request ) {
			foreach ( $needles as $needle ) {
				$this->assertStringNotContainsString( $needle, $request['url'], 'The token appears in a request URL.' );
			}
		}
		$this->assert_no_unmocked_http_requests();
	}

	/**
	 * D7: the token comes only from the constant. Filters and options with plausible names must not supply it.
	 */
	public function test_token_ignores_filters_and_options(): void {
		$this->assertFalse( defined( Updater::TOKEN_CONSTANT ), 'The test environment must not define ' . Updater::TOKEN_CONSTANT . '.' );

		$names = array(
			'steadhand_dev_github_token',
			'steadhand_github_token',
			'steadhand_dev_token',
			'steadhand_token',
			'steadhand_dev_updater_token',
			'puc_token',
		);
		foreach ( $names as $name ) {
			add_filter( $name, static fn() => self::DUMMY_TOKEN );
			update_option( $name, self::DUMMY_TOKEN );
		}

		$this->assertSame( '', Updater::token() );
	}

	/**
	 * D7: the token is read only from the constant. Source-level guard against other token sources and filter calls.
	 */
	public function test_the_updater_source_has_no_other_token_source(): void {
		$source = file_get_contents( STEADHAND_DIR . 'includes/Dev/Updater.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local plugin file.
		$this->assertIsString( $source );

		$forbidden = array( 'get_option(', 'get_site_option(', 'get_transient(', 'get_site_transient(', 'getenv(', '$_ENV', '$_SERVER', 'apply_filters(' );
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $source, 'Updater.php must not contain ' . $needle . ' (the token comes only from the constant).' );
		}
	}

	/**
	 * With neither a token nor the opt-in, register() must not load the library, schedule a check or store anything.
	 *
	 * Runs in its own process, so the library cannot already be loaded by another test in this run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_without_a_token_or_opt_in_does_not_load_the_library(): void {
		$this->assertFalse( defined( Updater::TOKEN_CONSTANT ), 'The test environment must not define ' . Updater::TOKEN_CONSTANT . '.' );
		$this->assertFalse( defined( Updater::OPT_IN_CONSTANT ), 'The test environment must not define ' . Updater::OPT_IN_CONSTANT . '.' );

		Updater::register();

		$loaded = array_filter(
			get_included_files(),
			static fn( string $file ): bool => str_ends_with( $file, '/includes/Dev/vendor/autoload.php' )
		);
		$this->assertSame( array(), $loaded, 'register() loaded the update-checker library without a token.' );
		$this->assertFalse( wp_next_scheduled( 'puc_cron_check_updates-steadhand' ), 'register() scheduled an update check without a token.' );

		$sentinel = new \stdClass();
		$this->assertSame( $sentinel, get_option( 'external_updates-steadhand', $sentinel ), 'register() stored update state without a token.' );
	}

	public function test_asset_pattern_matches_only_the_dev_zip(): void {
		$this->assertMatchesRegularExpression( Updater::ASSET_PATTERN, 'steadhand-dev-0.1.0.zip' );
		$this->assertDoesNotMatchRegularExpression( Updater::ASSET_PATTERN, 'steadhand-0.1.0.zip' );
		$this->assertDoesNotMatchRegularExpression( Updater::ASSET_PATTERN, 'steadhand-dev-0.1.0.zip.exe' );
		$this->assertDoesNotMatchRegularExpression( Updater::ASSET_PATTERN, "steadhand-dev-0.1.0.zip\n" );
	}

	/**
	 * Applies a checker's request-info filter to an info object, as the library does for a download.
	 *
	 * @param object $checker The checker.
	 * @param mixed  $info    The info object to filter.
	 * @return mixed The filtered info: the same object, or null when it was dropped.
	 */
	private function apply_request_info_filter( object $checker, mixed $info ): mixed {
		$info_hook = $checker->getUniqueName( 'request_info_result' );
		return apply_filters( $info_hook, $info, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The library's prefixed hook name for this checker.
	}

	/**
	 * Requests that carried an Authorization header.
	 *
	 * @return array<int, array{url: string, headers: array<string, string>, mocked: bool}>
	 */
	private function requests_with_authorization(): array {
		return array_values( array_filter( $this->http_log, static fn( array $request ): bool => isset( $request['headers']['authorization'] ) ) );
	}

	/**
	 * Fails the test if the update check consulted the tag or branch strategies.
	 */
	private function assert_only_latest_release_consulted(): void {
		foreach ( $this->http_log as $request ) {
			$path = (string) wp_parse_url( $request['url'], PHP_URL_PATH );
			$this->assertStringNotContainsString( '/tags', $path, 'The latest-tag strategy was consulted.' );
			$this->assertStringNotContainsString( '/branches/', $path, 'The branch strategy was consulted.' );
		}
	}

	/**
	 * Loads the library autoloader. register() does this only when a token is set, so tests load it themselves.
	 */
	private function load_library(): void {
		$autoload = STEADHAND_DIR . 'includes/Dev/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			$this->fail( 'Plugin Update Checker is not installed (missing includes/Dev/vendor). Run "composer dev-updater:install" and try again.' );
		}
		require_once $autoload;
	}

	/**
	 * Hook name => priority => number of callbacks, for comparing hook state before and after a call.
	 *
	 * @return array<string, array<int, int>>
	 */
	private function hook_counts(): array {
		$counts = array();
		foreach ( $GLOBALS['wp_filter'] as $hook_name => $wp_hook ) {
			if ( ! $wp_hook instanceof WP_Hook ) {
				continue;
			}
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				if ( count( $callbacks ) > 0 ) {
					$counts[ $hook_name ][ $priority ] = count( $callbacks );
				}
			}
		}
		return $counts;
	}
}
