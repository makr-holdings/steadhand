<?php
/**
 * The steadhand/get-diagnostics ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Diagnostics\SiteHealth;
use Steadhand\Abilities\Registry;
use Steadhand\Diagnostics\SelfCheck;
use Steadhand\Pipeline\Pipeline;
use WP\MCP\Core\McpAdapter;

/**
 * Covers SPEC §5 (get-diagnostics), §4.2 (steadhand_read), §3.2 (transport gate) and §10 (no secrets, no paths).
 */
final class GetDiagnosticsTest extends TestCase {

	private const TOOL_NAME = 'steadhand-get-diagnostics';
	private const ABILITY   = 'steadhand/get-diagnostics';
	private const HEALTH    = 'health-check-site-status-result';

	/**
	 * Calls a tool as the current user and returns the JSON-RPC result member.
	 *
	 * @param string               $tool      Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	private function tool_result( string $tool, array $arguments = array() ): array {
		return $this->call_tool( $tool, $arguments )['result'];
	}

	/**
	 * Calls get-diagnostics as the current agent and returns its structured output, asserting success and schema.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	private function report( array $arguments = array() ): array {
		$result = $this->tool_result( self::TOOL_NAME, $arguments );
		$this->assertFalse( $result['isError'], 'The tool must not fail: ' . ( $result['content'][0]['text'] ?? '' ) );
		$this->assertFalse( Pipeline::is_running(), 'The pipeline context must be cleared after the call.' );

		$report = $result['structuredContent'];
		$valid  = rest_validate_value_from_schema( $report, wp_get_ability( self::ABILITY )->get_output_schema(), 'output' );
		$this->assertNotInstanceOf( \WP_Error::class, $valid, 'The output must match the output schema.' );

		return $report;
	}

	/**
	 * Every scalar string in a decoded value, at any depth.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private function strings_in( mixed $value ): array {
		if ( is_string( $value ) ) {
			return array( $value );
		}
		$out = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				$out = array_merge( $out, $this->strings_in( $child ) );
			}
		}

		return $out;
	}

	/**
	 * The test bootstrap loads Steadhand without activating it, so the plugin list needs it activated for the test.
	 * The option is restored by the transaction that wraps every test.
	 */
	private function activate_steadhand(): void {
		update_option( 'active_plugins', array( plugin_basename( STEADHAND_FILE ) ) );
	}

	/**
	 * Stores a cron option with one overdue event and one future event, and returns the previous option value.
	 *
	 * @return mixed The previous value.
	 */
	private function set_cron_option(): mixed {
		$previous = get_option( 'cron' );
		$now      = time();

		update_option(
			'cron',
			array(
				$now - 3600 => array(
					'steadhand_test_overdue' => array(
						'abc' => array(
							'schedule' => false,
							'args'     => array( 'SECRET_CRON_ARG' ),
						),
					),
				),
				$now + 1800 => array(
					'steadhand_test_future' => array(
						'def' => array(
							'schedule' => false,
							'args'     => array(),
						),
					),
				),
				'version'   => 2,
			),
			true
		);

		return $previous;
	}

	public function test_agent_gets_a_well_formed_report(): void {
		wp_set_current_user( $this->make_agent() );

		$report = $this->report();

		$this->assertSame(
			array( 'environment', 'theme', 'plugins', 'php_errors', 'cron', 'site_health', 'steadhand' ),
			array_keys( $report )
		);
	}

	public function test_environment_matches_the_live_site(): void {
		wp_set_current_user( $this->make_agent() );

		$env = $this->report()['environment'];

		$this->assertSame( get_bloginfo( 'version' ), $env['wordpress_version'] );
		$this->assertSame( PHP_VERSION, $env['php_version'] );
		$this->assertSame( ini_get( 'memory_limit' ), $env['memory_limit'] );
		$this->assertSame( (int) ini_get( 'max_execution_time' ), $env['max_execution_time'] );
		$this->assertSame( is_multisite(), $env['is_multisite'] );
		$this->assertSame( wp_get_environment_type(), $env['environment_type'] );
		$this->assertSame( (bool) wp_using_ext_object_cache(), $env['object_cache'] );
		$this->assertSame( str_starts_with( home_url(), 'https://' ), $env['https'] );
		$this->assertSame( get_locale(), $env['locale'] );
		$this->assertContains( $env['database']['server'], array( 'MySQL', 'MariaDB', 'unknown' ) );
		$this->assertNotSame( '', $env['database']['version'] );
	}

	public function test_debug_flags_report_a_log_on_or_off_and_never_a_path(): void {
		wp_set_current_user( $this->make_agent() );

		$debug = $this->report()['environment']['debug'];

		$this->assertSame( defined( 'WP_DEBUG' ) && WP_DEBUG, $debug['wp_debug'] );
		$this->assertSame( true === WP_DEBUG_LOG || ( is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ), $debug['wp_debug_log'] );
		$this->assertIsBool( $debug['script_debug'] );
	}

	public function test_theme_section_matches_the_active_theme(): void {
		wp_set_current_user( $this->make_agent() );

		$theme = $this->report()['theme'];

		$this->assertSame( wp_get_theme()->get( 'Name' ), $theme['name'] );
		$this->assertSame( wp_get_theme()->get( 'Version' ), $theme['version'] );
		$this->assertFalse( $theme['is_child'] );
		$this->assertSame( '', $theme['parent_name'] );
		$this->assertSame( wp_is_block_theme(), $theme['is_block_theme'] );
	}

	public function test_plugins_list_active_plugins_by_name_and_version_without_paths_or_authors(): void {
		wp_set_current_user( $this->make_agent() );
		$this->activate_steadhand();

		$plugins = $this->report()['plugins'];
		$names   = array_column( $plugins['items'], 'name' );
		$encoded = (string) wp_json_encode( $plugins );

		$this->assertSame( 1, $plugins['active_count'] );
		$this->assertContains( 'Steadhand – Secure AI Agent Access for WordPress', $names );
		$this->assertStringNotContainsString( 'steadhand.php', $encoded );
		$this->assertStringNotContainsString( 'Author', $encoded );
		$this->assertFalse( $plugins['truncated'] );
	}

	public function test_plugin_update_status_comes_from_the_existing_transient_and_no_request_is_made(): void {
		wp_set_current_user( $this->make_agent() );
		$this->activate_steadhand();
		$file = plugin_basename( STEADHAND_FILE );
		$name = get_plugins()[ $file ]['Name'];

		set_site_transient( 'update_plugins', (object) array( 'response' => array( $file => (object) array( 'new_version' => '99.0' ) ) ) );
		$requests = 0;
		$recorder = static function () use ( &$requests ) {
			++$requests;
			return new \WP_Error( 'steadhand_test', 'No outbound requests are allowed in this test.' );
		};
		add_filter( 'pre_http_request', $recorder );

		try {
			$plugins = $this->report()['plugins'];
		} finally {
			remove_filter( 'pre_http_request', $recorder );
		}

		$item = array_values( array_filter( $plugins['items'], static fn( array $row ): bool => wp_strip_all_tags( $name ) === $row['name'] ) )[0] ?? null;
		$this->assertNotNull( $item, 'The active plugin must be listed.' );
		$this->assertTrue( $item['update_available'] );
		$this->assertSame( 0, $requests, 'Reading the plugin list must not contact any server.' );
	}

	public function test_cron_reports_the_overdue_and_next_event_and_never_their_arguments(): void {
		wp_set_current_user( $this->make_agent() );
		$previous = $this->set_cron_option();
		$stored   = get_option( 'cron' );

		try {
			$cron  = $this->report()['cron'];
			$after = get_option( 'cron' );
		} finally {
			update_option( 'cron', $previous, true );
		}

		$this->assertGreaterThanOrEqual( 1, $cron['overdue_count'] );
		$hooks = array_column( $cron['overdue'], 'hook' );
		$this->assertContains( 'steadhand_test_overdue', $hooks );
		$this->assertGreaterThanOrEqual( 3600, $cron['overdue'][0]['overdue_seconds'] );
		$this->assertLessThanOrEqual( 1800, $cron['next_event_in_seconds'] );
		$this->assertGreaterThan( 1800 - 60, $cron['next_event_in_seconds'] );
		$this->assertSame( 2, $cron['total_events'] );
		$this->assertStringNotContainsString( 'SECRET_CRON_ARG', (string) wp_json_encode( $cron ) );
		$this->assertSame( $stored, $after, 'The call must not rewrite the cron option.' );
	}

	public function test_site_health_is_unavailable_when_the_transient_is_absent(): void {
		wp_set_current_user( $this->make_agent() );
		delete_transient( self::HEALTH );

		$health = $this->report()['site_health'];

		$this->assertSame(
			array(
				'available'   => false,
				'good'        => null,
				'recommended' => null,
				'critical'    => null,
			),
			$health
		);
	}

	public function test_site_health_reads_valid_stored_counts(): void {
		wp_set_current_user( $this->make_agent() );
		set_transient(
			self::HEALTH,
			(string) wp_json_encode(
				array(
					'good'        => 10,
					'recommended' => 2,
					'critical'    => 1,
				)
			)
		);

		$this->assertSame(
			array(
				'available'   => true,
				'good'        => 10,
				'recommended' => 2,
				'critical'    => 1,
			),
			$this->report()['site_health']
		);
	}

	public function test_site_health_treats_garbage_as_unavailable(): void {
		wp_set_current_user( $this->make_agent() );
		set_transient( self::HEALTH, 'this is not json {' );

		$health = $this->report()['site_health'];

		$this->assertFalse( $health['available'] );
		$this->assertNull( $health['good'] );
	}

	public function test_site_health_treats_a_wrong_shape_as_unavailable(): void {
		$this->assertNull(
			SiteHealth::counts(
				(string) wp_json_encode(
					array(
						'good'        => '5',
						'recommended' => 0,
						'critical'    => 0,
					)
				)
			)
		);
		$this->assertNull(
			SiteHealth::counts(
				(string) wp_json_encode(
					array(
						'good'        => -1,
						'recommended' => 0,
						'critical'    => 0,
					)
				)
			)
		);
		$this->assertNull( SiteHealth::counts( (string) wp_json_encode( array( 'good' => 1 ) ) ) );
		$this->assertNull( SiteHealth::counts( array( 'good' => 1 ) ) );
	}

	public function test_an_expired_site_health_transient_is_ignored_and_not_deleted(): void {
		wp_set_current_user( $this->make_agent() );
		update_option(
			'_transient_health-check-site-status-result',
			(string) wp_json_encode(
				array(
					'good'        => 1,
					'recommended' => 0,
					'critical'    => 0,
				)
			),
			false
		);
		update_option( '_transient_timeout_health-check-site-status-result', time() - 100, false );

		$health = $this->report()['site_health'];

		$this->assertFalse( $health['available'] );
		$this->assertNotFalse(
			get_option( '_transient_health-check-site-status-result', false ),
			'An expired transient must be left in place, not deleted.'
		);
	}

	public function test_the_steadhand_self_check_is_true_in_the_normal_environment(): void {
		wp_set_current_user( $this->make_agent() );

		$steadhand = $this->report()['steadhand'];

		$this->assertSame( STEADHAND_VERSION, $steadhand['version'] );
		$this->assertSame( McpAdapter::VERSION, $steadhand['mcp_adapter_version'] );
		$this->assertTrue( $steadhand['ability_category_registered'] );
		$this->assertSame( 17, $steadhand['abilities_registered'] );
		$this->assertTrue( $steadhand['server_registered'] );
		$this->assertTrue( $steadhand['lockdown_active'] );
		$this->assertTrue( $steadhand['change_log_ready'] );
	}

	public function test_php_errors_is_well_formed_whatever_the_test_log_setting_is(): void {
		wp_set_current_user( $this->make_agent() );

		$errors = $this->report()['php_errors'];

		$this->assertContains( $errors['reason'], array( 'ok', 'logging_off', 'no_log_file', 'unreadable', 'outside_allowed_location' ) );
		$this->assertSame( 'ok' === $errors['reason'], $errors['available'] );
		$this->assertLessThanOrEqual( 262144, $errors['scanned_bytes'] );
		$this->assertLessThanOrEqual( 20, count( $errors['items'] ) );
	}

	public function test_max_errors_limits_the_php_error_items(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assertLessThanOrEqual( 3, count( $this->report( array( 'max_errors' => 3 ) )['php_errors']['items'] ) );
	}

	public function test_the_output_holds_no_absolute_path_credential_or_salt(): void {
		wp_set_current_user( $this->make_agent() );

		$values  = $this->strings_in( $this->report() );
		$secrets = array_filter(
			array_merge(
				array( ABSPATH, WP_CONTENT_DIR ),
				array( DB_PASSWORD, DB_USER, DB_HOST, DB_NAME ),
				array( $GLOBALS['table_prefix'] ?? '' ),
				array_map(
					static fn( string $constant ): string => defined( $constant ) ? (string) constant( $constant ) : '',
					array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' )
				)
			),
			static fn( string $secret ): bool => strlen( $secret ) >= 4
		);

		foreach ( $values as $value ) {
			foreach ( $secrets as $secret ) {
				$this->assertStringNotContainsString( $secret, $value, 'A secret or path must never appear in the output.' );
			}
		}
	}

	public function test_an_agent_without_steadhand_read_is_refused(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );

		$result = $this->tool_result( self::TOOL_NAME );

		$this->assertTrue( $result['isError'] );
		$this->assertArrayNotHasKey( 'structuredContent', $result );
		$this->assertStringContainsString( 'steadhand_read', $result['content'][0]['text'] );
	}

	public function test_an_administrator_is_refused_at_the_transport_gate(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->mcp(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'steadhand-tests',
					'version' => '1',
				),
			)
		);

		$this->assertNotSame( 200, $response->get_status() );
	}

	public function test_unknown_arguments_and_out_of_range_max_errors_are_refused(): void {
		wp_set_current_user( $this->make_agent() );

		foreach ( array( array( 'extra' => 'x' ), array( 'max_errors' => 0 ), array( 'max_errors' => 51 ), array( 'max_errors' => 'many' ) ) as $arguments ) {
			$result = $this->tool_result( self::TOOL_NAME, $arguments );

			$this->assertTrue( $result['isError'], 'Refused: ' . wp_json_encode( $arguments ) );
			$this->assertArrayNotHasKey( 'structuredContent', $result );
		}
	}

	public function test_the_call_makes_no_outbound_request(): void {
		wp_set_current_user( $this->make_agent() );
		$session  = $this->mcp_session();
		$requests = 0;
		$recorder = static function () use ( &$requests ) {
			++$requests;
			return new \WP_Error( 'steadhand_test', 'No outbound requests are allowed in this test.' );
		};
		add_filter( 'pre_http_request', $recorder );

		try {
			$this->mcp(
				'tools/call',
				array(
					'name'      => self::TOOL_NAME,
					'arguments' => new \stdClass(),
				),
				$session
			);
		} finally {
			remove_filter( 'pre_http_request', $recorder );
		}

		$this->assertSame( 0, $requests );
	}

	public function test_the_call_writes_no_option_and_no_transient(): void {
		wp_set_current_user( $this->make_agent() );
		$session = $this->mcp_session();

		$before = $this->write_snapshot();
		$this->mcp(
			'tools/call',
			array(
				'name'      => self::TOOL_NAME,
				'arguments' => new \stdClass(),
			),
			$session
		);
		$after = $this->write_snapshot();

		$this->assertSame( $before, $after, 'The report must not add, change or remove an option or transient.' );
	}

	/**
	 * The options row count and the option names of every transient, for before-and-after comparison.
	 *
	 * @return array{rows: int, transients: string[], cron: mixed, update_plugins: mixed}
	 */
	private function write_snapshot(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the options table directly, uncached, to prove that nothing was written.
		$rows       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name", '\_transient\_%', '\_site\_transient\_%' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'rows'           => $rows,
			'transients'     => $transients,
			'cron'           => get_option( 'cron' ),
			'update_plugins' => get_option( '_site_transient_update_plugins' ),
		);
	}

	/**
	 * Number of guard callbacks attached to pre_http_request at the priority that GetDiagnostics uses.
	 *
	 * @return int
	 */
	private function http_guards(): int {
		global $wp_filter;

		$hook = $wp_filter['pre_http_request'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return 0;
		}

		return count( $hook->callbacks[ PHP_INT_MAX - 1 ] ?? array() );
	}

	public function test_a_persistent_object_cache_site_transient_read_cannot_send_a_request(): void {
		wp_set_current_user( $this->make_agent() );
		$was_external = wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );

		$would_send = 0;
		$observer   = static function ( mixed $preempt ) use ( &$would_send ): mixed {
			if ( false === $preempt ) {
				++$would_send;
			}

			return $preempt;
		};
		$fetcher    = static function ( mixed $value ): mixed {
			wp_remote_get( 'https://example.invalid/steadhand-test' );

			return $value;
		};

		add_filter( 'pre_http_request', $observer, PHP_INT_MAX, 3 );
		add_filter( 'site_transient_update_plugins', $fetcher, 10, 1 );

		try {
			$result = $this->tool_result( self::TOOL_NAME );
		} finally {
			remove_filter( 'site_transient_update_plugins', $fetcher, 10 );
			remove_filter( 'pre_http_request', $observer, PHP_INT_MAX );
			wp_using_ext_object_cache( $was_external );
		}

		$this->assertFalse( $result['isError'], 'The call must still succeed.' );
		$this->assertSame( 0, $would_send, 'No request may be sent while the report is built.' );
	}

	public function test_the_http_guard_is_gone_after_the_call(): void {
		wp_set_current_user( $this->make_agent() );
		$before = $this->http_guards();

		$this->report();

		$this->assertSame( $before, $this->http_guards() );
	}

	public function test_the_http_guard_is_gone_when_a_section_throws(): void {
		wp_set_current_user( $this->make_agent() );
		$before  = $this->http_guards();
		$thrower = static function (): never {
			throw new \RuntimeException( 'steadhand test section failure' );
		};

		add_filter( 'option_timezone_string', $thrower, 10, 1 );
		try {
			$result = $this->tool_result( self::TOOL_NAME );
		} finally {
			remove_filter( 'option_timezone_string', $thrower, 10 );
		}

		$this->assertTrue( $result['isError'] ?? true, 'A section that throws must not return a report: ' . wp_json_encode( $result ) );
		$this->assertSame( $before, $this->http_guards(), 'The guard must be removed even when a section throws.' );
	}

	/**
	 * Removes one Lockdown callback and returns what is needed to put it back.
	 *
	 * @param string $hook   Hook name.
	 * @param string $method Method of the Lockdown object.
	 * @return array{0: string, 1: callable, 2: int, 3: int}
	 */
	private function detach_lockdown_hook( string $hook, string $method ): array {
		global $wp_filter;

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && ( $function[0] ?? null ) instanceof \Steadhand\Lockdown && ( $function[1] ?? '' ) === $method ) {
					remove_filter( $hook, $function, $priority );

					return array( $hook, $function, $priority, (int) $callback['accepted_args'] );
				}
			}
		}

		$this->fail( "No lockdown callback $method is attached to $hook." );
	}

	public function test_lockdown_active_is_false_while_a_lockdown_hook_is_removed(): void {
		wp_set_current_user( $this->make_agent() );
		$this->assertTrue( $this->report()['steadhand']['lockdown_active'], 'The lockdown must be active before the test removes a hook.' );

		$detached = $this->detach_lockdown_hook( 'admin_init', 'block_agents_in_admin' );
		try {
			$this->assertFalse( $this->report()['steadhand']['lockdown_active'] );
		} finally {
			add_filter( $detached[0], $detached[1], $detached[2], $detached[3] );
		}

		$this->assertTrue( $this->report()['steadhand']['lockdown_active'], 'The hook must be back after the test.' );
	}

	public function test_ability_category_is_reported_missing_when_it_is_not_registered(): void {
		wp_set_current_user( $this->make_agent() );
		$this->report();
		$this->assertTrue( SelfCheck::report()['ability_category_registered'] );

		wp_unregister_ability_category( Registry::CATEGORY );
		try {
			$this->assertFalse( SelfCheck::report()['ability_category_registered'] );
		} finally {
			// Core accepts a category only inside its init action. The registry that action fills is used here directly,
			// so that the other categories are not registered a second time.
			\WP_Ability_Categories_Registry::get_instance()->register(
				Registry::CATEGORY,
				array(
					'label'       => __( 'Steadhand', 'steadhand' ),
					'description' => __( 'Abilities that let AI agents work on this site safely, under least-privilege capabilities and an audit log.', 'steadhand' ),
				)
			);
		}

		$this->assertTrue( SelfCheck::report()['ability_category_registered'] );
	}

	public function test_an_adapter_without_the_server_method_reports_no_server_and_does_not_fail(): void {
		$without_method = new class() {};

		$this->assertFalse( SelfCheck::has_server( $without_method ) );
	}

	public function test_an_adapter_whose_server_is_absent_reports_no_server(): void {
		$absent = new class() {
			public function get_server( string $id ): ?object {
				unset( $id );

				return null;
			}
		};

		$this->assertFalse( SelfCheck::has_server( $absent ) );
	}

	public function test_the_whole_report_stays_valid_for_hostile_cron_and_site_health_data(): void {
		wp_set_current_user( $this->make_agent() );
		$previous        = get_option( 'cron' );
		$previous_health = get_transient( self::HEALTH );

		try {
			foreach ( DiagnosticsBoundsTest::hostile_cron_options() as $option ) {
				$option['version'] = $option['version'] ?? 2;
				update_option( 'cron', $option, true );
				set_transient( self::HEALTH, DiagnosticsBoundsTest::hostile_site_health()[0], HOUR_IN_SECONDS );

				$report = $this->report();

				$this->assertLessThanOrEqual( 1000000000, $report['cron']['overdue'][0]['overdue_seconds'] ?? 0 );
			}
		} finally {
			update_option( 'cron', $previous, true );
			if ( false === $previous_health ) {
				delete_transient( self::HEALTH );
			} else {
				set_transient( self::HEALTH, $previous_health, HOUR_IN_SECONDS );
			}
		}
	}
}
