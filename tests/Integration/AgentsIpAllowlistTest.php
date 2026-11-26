<?php
/**
 * The Allowed IP addresses of an agent on the Agents screen: the save action, the screen, and what the agent can never see or change.
 * The negative tests come first.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\AgentsHandler;
use Steadhand\Admin\AgentsScreen;
use Steadhand\Capabilities;
use Steadhand\IpAllowlist;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Covers SPEC §4.1 and D28 for the allowed-addresses section, its save action (AgentsHandler::set_ips()), and the places the list must not reach.
 */
final class AgentsIpAllowlistTest extends TestCase {

	/** The stored list, as a test reads it. */
	private const META = IpAllowlist::META_KEY;

	/**
	 * Filters and actions this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * The handler under test.
	 *
	 * @var AgentsHandler
	 */
	private AgentsHandler $handler;

	/**
	 * The screen under test.
	 *
	 * @var AgentsScreen
	 */
	private AgentsScreen $screen;

	/**
	 * The $_SERVER keys this test may change, with their values before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $server_before = array();

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new AgentsHandler( ( new Plugin() )->registry() );
		$this->screen  = new AgentsScreen( $this->handler );

		// A refusal throws instead of ending the test run, and the test catches it.
		$thrower = static function ( $message ): void {
			throw new \WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double; the message is never printed.
		};
		$this->on( 'wp_die_handler', static fn() => $thrower, PHP_INT_MAX );
		$this->on( 'wp_is_application_passwords_available', '__return_true' );

		foreach ( array( 'REMOTE_ADDR', 'REQUEST_METHOD' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Saved and restored, never printed.
			$this->server_before[ $key ] = $_SERVER[ $key ] ?? null;
		}
		$_SERVER['REMOTE_ADDR']    = '203.0.113.44';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_GET                      = array();
	}

	protected function tearDown(): void {
		foreach ( $this->added as $added ) {
			remove_filter( $added[0], $added[1], $added[2] );
		}
		$this->added = array();

		foreach ( $this->server_before as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		$_POST = array();
		$_GET  = array();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Adds a hook that this test removes again in tearDown.
	 *
	 * @param string   $hook     The hook.
	 * @param callable $callback The callback.
	 * @param int      $priority The priority.
	 */
	private function on( string $hook, callable $callback, int $priority = 10 ): void {
		add_filter( $hook, $callback, $priority, 10 );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * A dedicated agent with one connection, and its list set when one is given.
	 *
	 * @param string[]|null $entries The list, or null for none.
	 * @return int The agent's ID.
	 */
	private function agent( ?array $entries = null ): int {
		$id   = self::factory()->user->create(
			array(
				'role'       => Roles::ROLE,
				'user_login' => 'agent' . wp_rand( 1000, 999999 ),
			)
		);
		$user = get_userdata( $id );
		$user->add_cap( Capabilities::CONNECT, true );
		\WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Desktop' ) );

		if ( null !== $entries ) {
			update_user_meta( $id, self::META, $entries );
		}

		return $id;
	}

	/**
	 * An administrator who may manage agents, made the current user.
	 */
	private function as_administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The nonce for an action on a target.
	 *
	 * @param string $action One of the AgentsHandler::ACTION_* values.
	 * @param int    $target The target user.
	 * @return string
	 */
	private function nonce( string $action, int $target ): string {
		return wp_create_nonce( AgentsHandler::nonce_action( $action, $target ) );
	}

	/**
	 * The rows of the list meta for an agent, as core stores them.
	 *
	 * @param int $id The user.
	 * @return array<int, mixed>
	 */
	private function stored( int $id ): array {
		return get_user_meta( $id, self::META, false );
	}

	/**
	 * The rendered Agents screen for the current user and $_GET.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		try {
			$this->screen->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Records each call of an action: the arguments of every call, in order.
	 *
	 * @param string $hook The action.
	 * @return \ArrayObject<int, array<int, mixed>>
	 */
	private function record( string $hook ): \ArrayObject {
		$seen = new \ArrayObject();
		$this->on(
			$hook,
			static function ( ...$args ) use ( $seen ): void {
				$seen[] = $args;
			}
		);

		return $seen;
	}

	public function test_a_request_without_a_valid_nonce_dies_and_changes_nothing(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();

		$this->expectException( \WPDieException::class );
		try {
			$this->handler->set_ips( 'not-a-nonce', $agent, '198.51.100.1' );
		} finally {
			$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
		}
	}

	public function test_a_nonce_for_another_agent_dies_and_changes_nothing(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$other = $this->agent();
		$this->as_administrator();

		$this->expectException( \WPDieException::class );
		try {
			$this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $other ), $agent, '198.51.100.1' );
		} finally {
			$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
			$this->assertSame( array(), IpAllowlist::for_user( $other ) );
		}
	}

	public function test_a_nonce_for_another_action_dies_and_changes_nothing(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();

		$this->expectException( \WPDieException::class );
		try {
			$this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, '198.51.100.1' );
		} finally {
			$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
		}
	}

	public function test_a_caller_without_manage_options_dies_and_changes_nothing(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->expectException( \WPDieException::class );
		try {
			$this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '198.51.100.1' );
		} finally {
			$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
		}
	}

	public function test_a_caller_who_holds_the_agent_role_dies_even_with_administrator_rights(): void {
		$agent  = $this->agent( array( '203.0.113.0/24' ) );
		$caller = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $caller )->add_role( Roles::ROLE );
		wp_set_current_user( $caller );

		$this->expectException( \WPDieException::class );
		try {
			$this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '198.51.100.1' );
		} finally {
			$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
		}
	}

	/**
	 * Targets that are not dedicated agents: an administrator, a user that does not exist, and ID 0. Each is refused with
	 * the not_agent code, and nothing is written for it.
	 */
	public function test_a_target_that_is_not_a_dedicated_agent_is_refused_and_nothing_is_written(): void {
		$this->as_administrator();
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		foreach ( array( $admin, $editor, 999999, 0 ) as $target ) {
			$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $target ), $target, '198.51.100.1' );

			$this->assertSame( 'not_agent', $result['code'], "Target $target must be refused." );
			$this->assertFalse( $result['ok'] );
			if ( $target > 0 && $target < 999999 ) {
				$this->assertSame( array(), $this->stored( $target ) );
			}
		}
	}

	public function test_on_a_multisite_network_nothing_is_saved(): void {
		$agent   = $this->agent();
		$network = new AgentsHandler( ( new Plugin() )->registry(), true );
		$this->as_administrator();

		$result = $network->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '198.51.100.1' );

		$this->assertSame( 'multisite', $result['code'] );
		$this->assertSame( array(), $this->stored( $agent ) );
	}

	public function test_text_longer_than_2000_bytes_is_refused_and_nothing_is_saved(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();
		$raw = str_repeat( "10.0.0.1\n", 250 );
		$this->assertGreaterThan( AgentsHandler::IPS_MAX_BYTES, strlen( $raw ) );

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, $raw );

		$this->assertSame( 'ips_invalid', $result['code'] );
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_one_bad_line_among_good_ones_saves_nothing_and_keeps_the_previous_list(): void {
		$agent   = $this->agent( array( '203.0.113.0/24' ) );
		$changes = $this->record( 'steadhand_agent_ips_changed' );
		$this->as_administrator();

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, "198.51.100.1\nexample.com\n10.0.0.1" );

		$this->assertSame( 'ips_invalid', $result['code'] );
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
		$this->assertCount( 0, $changes, 'A refused save fires nothing.' );
	}

	public function test_21_entries_are_refused_and_nothing_is_saved(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();
		$lines = array();
		for ( $i = 1; $i <= 21; $i++ ) {
			$lines[] = "10.0.0.$i";
		}

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, implode( "\n", $lines ) );

		$this->assertSame( 'ips_invalid', $result['code'] );
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_a_save_stores_the_canonical_form_and_reads_it_back(): void {
		$agent = $this->agent();
		$this->as_administrator();

		$result = $this->handler->set_ips(
			$this->nonce( AgentsHandler::ACTION_IPS, $agent ),
			$agent,
			"203.0.113.7/24\n2001:DB8::1\n203.0.113.9/24\n"
		);

		$this->assertSame( 'ips_saved', $result['code'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( '203.0.113.0/24', '2001:db8::1' ), IpAllowlist::for_user( $agent ) );
		$this->assertSame( array( array( '203.0.113.0/24', '2001:db8::1' ) ), $this->stored( $agent ) );
	}

	public function test_the_text_is_unslashed_once_as_wordpress_sends_it(): void {
		$agent = $this->agent();
		$this->as_administrator();

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, wp_slash( '203.0.113.5' ) );

		$this->assertSame( 'ips_saved', $result['code'] );
		$this->assertSame( array( '203.0.113.5' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_empty_text_deletes_the_list_and_turns_the_check_off(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, "\n  \n" );

		$this->assertSame( 'ips_saved', $result['code'] );
		$this->assertSame( array(), $this->stored( $agent ) );
		$this->assertSame( array(), IpAllowlist::for_user( $agent ) );
	}

	public function test_the_changed_action_fires_only_when_the_stored_list_changed(): void {
		$agent   = $this->agent();
		$changes = $this->record( 'steadhand_agent_ips_changed' );
		$this->as_administrator();
		$nonce = $this->nonce( AgentsHandler::ACTION_IPS, $agent );

		$this->handler->set_ips( $nonce, $agent, '203.0.113.0/24' );
		$this->assertSame( array( array( $agent, array( '203.0.113.0/24' ), array() ) ), $changes->getArrayCopy() );

		$this->handler->set_ips( $nonce, $agent, '203.0.113.0/24' );
		$this->assertCount( 1, $changes, 'The same list saved again is not a change.' );

		$this->handler->set_ips( $nonce, $agent, '' );
		$this->assertCount( 2, $changes );
		$this->assertSame( array( $agent, array(), array( '203.0.113.0/24' ) ), $changes[1] );
	}

	public function test_replacing_a_corrupt_list_counts_as_a_change(): void {
		$agent = $this->agent();
		update_user_meta( $agent, self::META, 'junk' );
		$changes = $this->record( 'steadhand_agent_ips_changed' );
		$this->as_administrator();

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '203.0.113.5' );

		$this->assertSame( 'ips_saved', $result['code'] );
		$this->assertSame( array( array( $agent, array( '203.0.113.5' ), array() ) ), $changes->getArrayCopy() );
	}

	public function test_a_listener_that_throws_on_the_changed_action_does_not_change_the_result(): void {
		$agent = $this->agent();
		$this->on(
			'steadhand_agent_ips_changed',
			static function (): void {
				throw new \RuntimeException( 'The audit listener broke.' );
			}
		);
		$this->as_administrator();

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '203.0.113.5' );

		$this->assertSame( 'ips_saved', $result['code'] );
		$this->assertSame( array( '203.0.113.5' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_a_save_from_the_form_redirects_with_the_fixed_code_and_nothing_else(): void {
		$agent = $this->agent();
		$this->as_administrator();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			AgentsHandler::FIELD => AgentsHandler::ACTION_IPS,
			'user_id'            => (string) $agent,
			'_wpnonce'           => $this->nonce( AgentsHandler::ACTION_IPS, $agent ),
			'ips'                => '203.0.113.5',
		);

		$url = (string) $this->screen->process_post();

		$this->assertStringContainsString( 'agents=ips_saved', $url );
		$this->assertStringContainsString( 'agent=' . $agent, $url );
		$this->assertSame( array( '203.0.113.5' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_a_get_request_changes_nothing(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();
		$_POST = array(
			AgentsHandler::FIELD => AgentsHandler::ACTION_IPS,
			'user_id'            => (string) $agent,
			'ips'                => '198.51.100.1',
		);

		$this->assertNull( $this->screen->process_post() );
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_the_screen_never_prints_the_text_that_was_sent(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			AgentsHandler::FIELD => AgentsHandler::ACTION_IPS,
			'user_id'            => (string) $agent,
			'_wpnonce'           => $this->nonce( AgentsHandler::ACTION_IPS, $agent ),
			'ips'                => '<script>alert(1)</script>',
		);
		$url                       = (string) $this->screen->process_post();
		$this->assertStringNotContainsString( '<script>', $url );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_GET                      = array(
			'page'   => AgentsScreen::SLUG,
			'agent'  => (string) $agent,
			'agents' => 'ips_invalid',
		);
		$html                      = $this->render();

		$this->assertStringContainsString( 'were not saved', $html, 'The fixed notice is shown.' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html );
	}

	public function test_the_detail_view_shows_the_stored_entries_in_the_form(): void {
		$agent = $this->agent( array( '203.0.113.0/24', '2001:db8::1' ) );
		$this->as_administrator();
		$_GET = array(
			'page'  => AgentsScreen::SLUG,
			'agent' => (string) $agent,
		);

		$html = $this->render();

		$this->assertStringContainsString( 'name="ips"', $html );
		$this->assertMatchesRegularExpression( '/<textarea[^>]*name="ips"[^>]*>203\.0\.113\.0\/24\n2001:db8::1<\/textarea>/', $html );
	}

	public function test_a_corrupt_stored_list_shows_the_warning_and_its_text_is_escaped(): void {
		$agent = $this->agent();
		update_user_meta( $agent, self::META, '<script>x</script>' );
		$this->as_administrator();
		$_GET = array(
			'page'  => AgentsScreen::SLUG,
			'agent' => (string) $agent,
		);

		$html = $this->render();

		$this->assertStringContainsString( 'refused from every address until the list is saved again', $html );
		$this->assertStringNotContainsString( '<script>x</script>', $html );
	}

	public function test_the_section_sits_after_the_connections_and_before_connect_a_client(): void {
		$agent = $this->agent();
		$this->as_administrator();
		$_GET = array(
			'page'  => AgentsScreen::SLUG,
			'agent' => (string) $agent,
		);

		$html = $this->render();

		$connections = strpos( $html, '<h3>Connections</h3>' );
		$allowed     = strpos( $html, 'Allowed IP addresses</h3>' );
		$connect     = strpos( $html, '<h3>Connect a client</h3>' );
		$this->assertNotFalse( $connections );
		$this->assertNotFalse( $allowed );
		$this->assertNotFalse( $connect );
		$this->assertTrue( $connections < $allowed && $allowed < $connect );
	}

	public function test_the_screen_shows_the_address_this_server_sees_for_the_viewers_own_request(): void {
		$agent = $this->agent();
		$this->as_administrator();
		$_GET = array(
			'page'  => AgentsScreen::SLUG,
			'agent' => (string) $agent,
		);

		$this->assertStringContainsString( 'This server sees your own request coming from:', $this->render() );
		$this->assertStringContainsString( '203.0.113.44', $this->render() );

		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertStringContainsString( 'could not be determined', $this->render() );
	}

	public function test_the_agents_list_has_no_allowed_addresses_column_or_form(): void {
		$this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();

		$html = $this->render();

		$this->assertStringNotContainsString( 'Allowed IP addresses', $html );
		$this->assertStringNotContainsString( 'name="ips"', $html );
	}

	public function test_disable_and_enable_do_not_touch_the_list(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();

		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );

		$this->handler->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent );
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_deleting_an_agent_removes_its_list_with_the_user(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'agent_deleted', $result['code'] );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts the rows left behind; no API reads a row for a deleted user.
		$left = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id = %d", self::META, $agent ) );
		$this->assertSame( '0', (string) $left, 'The list must not outlive the user.' );
	}

	public function test_no_ability_or_its_schema_reads_or_writes_the_list(): void {
		$directories = array(
			dirname( __DIR__, 2 ) . '/includes/Abilities',
			dirname( __DIR__, 2 ) . '/includes/Pipeline',
			dirname( __DIR__, 2 ) . '/includes/Diagnostics',
			dirname( __DIR__, 2 ) . '/includes/Guardrails',
		);

		$checked = 0;
		foreach ( $directories as $directory ) {
			$files = glob( $directory . '/*.php' );
			foreach ( false === $files ? array() : $files as $file ) {
				++$checked;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a plugin source file of this repository.
				$source = (string) file_get_contents( $file );
				$this->assertStringNotContainsString( 'steadhand_allowed_ips', $source, basename( $file ) );
				$this->assertStringNotContainsString( 'IpAllowlist', $source, basename( $file ) );
			}
		}

		$this->assertGreaterThan( 20, $checked, 'The scan must cover the ability, pipeline, diagnostics and guardrail sources.' );
	}

	public function test_the_read_tools_output_never_contains_the_list(): void {
		$agent = $this->agent( array( '203.0.113.0/24', '2001:db8::/48' ) );
		wp_set_current_user( $agent );

		foreach ( array( 'steadhand-get-site-info', 'steadhand-get-diagnostics' ) as $tool ) {
			$encoded = (string) wp_json_encode( $this->call_tool( $tool ) );

			$this->assertStringNotContainsString( '203.0.113', $encoded, $tool );
			$this->assertStringNotContainsString( '2001:db8', $encoded, $tool );
			$this->assertStringNotContainsString( 'allowed_ips', $encoded, $tool );
		}
	}

	public function test_a_refused_first_write_of_a_list_never_leaves_the_agent_without_a_list(): void {
		$agent = $this->agent();
		$this->as_administrator();
		$this->on(
			'update_user_metadata',
			static fn( $check, $id, $key, $value ) => is_array( $value ) ? false : $check,
			10
		);

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '198.51.100.1' );

		$this->assertSame( 'ips_failed', $result['code'] );
		$this->assertNull( IpAllowlist::for_user( $agent ), 'The save failed, so the agent must be refused, not read as having no list.' );
	}

	public function test_a_refused_update_keeps_the_previous_list_in_place(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();
		$this->on( 'update_user_metadata', static fn() => false, 10 );

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '198.51.100.1' );

		$this->assertSame( 'ips_failed', $result['code'] );
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_a_throwing_listener_on_the_write_does_not_leave_the_agent_open(): void {
		$agent = $this->agent();
		$this->as_administrator();
		$throw = static function (): void {
			throw new \RuntimeException( 'The audit listener broke.' );
		};
		$this->on( 'added_user_meta', $throw, 10 );
		$this->on( 'updated_user_meta', $throw, 10 );

		$this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '198.51.100.1' );

		$this->assertSame( array( '198.51.100.1' ), IpAllowlist::for_user( $agent ), 'The write landed, so the agent holds the new list.' );
	}

	public function test_a_refused_removal_keeps_the_list_and_is_reported_as_failed(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();
		$this->on( 'delete_user_metadata', static fn() => false, 10 );

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '' );

		$this->assertSame( 'ips_failed', $result['code'] );
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::for_user( $agent ) );
	}

	public function test_a_save_over_two_stored_rows_is_never_read_as_open(): void {
		$agent = $this->agent();
		$this->as_administrator();
		add_user_meta( $agent, self::META, array( '203.0.113.0/24' ) );
		add_user_meta( $agent, self::META, array( '203.0.113.0/24' ) );

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '198.51.100.1' );

		$this->assertNotSame( array(), IpAllowlist::for_user( $agent ), 'Two rows must never read as no list.' );
		$this->assertSame( 'ips_failed', $result['code'] );
		$this->assertNull( IpAllowlist::for_user( $agent ), 'Two rows are unreadable, so the agent is refused.' );
	}

	public function test_empty_text_does_not_remove_an_unreadable_list_without_the_clear_box(): void {
		$agent = $this->agent();
		update_user_meta( $agent, self::META, 'junk' );
		$this->as_administrator();

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '' );

		$this->assertSame( 'ips_invalid', $result['code'] );
		$this->assertNull( IpAllowlist::for_user( $agent ), 'An unreadable list stays unreadable, so the agent stays refused.' );
	}

	public function test_the_clear_box_removes_an_unreadable_list(): void {
		$agent = $this->agent();
		update_user_meta( $agent, self::META, 'junk' );
		$this->as_administrator();

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '', true );

		$this->assertSame( 'ips_saved', $result['code'] );
		$this->assertSame( array(), IpAllowlist::for_user( $agent ) );
	}

	public function test_empty_text_still_clears_a_healthy_list_without_the_box(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$this->as_administrator();

		$result = $this->handler->set_ips( $this->nonce( AgentsHandler::ACTION_IPS, $agent ), $agent, '' );

		$this->assertSame( 'ips_saved', $result['code'] );
		$this->assertSame( array(), IpAllowlist::for_user( $agent ) );
	}

	public function test_the_form_offers_the_clear_box_only_for_an_unreadable_list(): void {
		$agent = $this->agent();
		update_user_meta( $agent, self::META, 'junk' );
		$this->as_administrator();
		$_GET = array(
			'page'  => AgentsScreen::SLUG,
			'agent' => (string) $agent,
		);
		$this->assertStringContainsString( 'name="ips_clear"', $this->render() );

		update_user_meta( $agent, self::META, array( '203.0.113.0/24' ) );
		$this->assertStringNotContainsString( 'name="ips_clear"', $this->render() );
	}

	public function test_a_viewer_who_cannot_edit_sees_an_unreadable_list_as_unreadable_not_as_any_address(): void {
		$agent = $this->agent();
		update_user_meta( $agent, self::META, 'junk' );
		add_role(
			'steadhand_test_viewer',
			'Viewer',
			array(
				'read'           => true,
				'manage_options' => true,
				'list_users'     => true,
			)
		);
		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'steadhand_test_viewer' ) ) );
			$_GET = array(
				'page'  => AgentsScreen::SLUG,
				'agent' => (string) $agent,
			);

			$html = $this->render();
		} finally {
			remove_role( 'steadhand_test_viewer' );
		}

		$this->assertStringContainsString( 'cannot be read', $html );
		$this->assertStringNotContainsString( 'Any address', $html );
		$this->assertStringNotContainsString( 'name="ips"', $html );
	}

	public function test_the_own_address_is_looked_up_with_user_id_zero_not_the_agent(): void {
		$agent = $this->agent( array( '203.0.113.0/24' ) );
		$seen  = array();
		$this->on(
			'steadhand_client_ip',
			static function ( $ip, $user_id ) use ( &$seen ) {
				$seen[] = $user_id;
				return $ip;
			},
			PHP_INT_MAX
		);
		$this->as_administrator();
		$_GET = array(
			'page'  => AgentsScreen::SLUG,
			'agent' => (string) $agent,
		);

		$this->render();

		$this->assertNotEmpty( $seen );
		$this->assertSame( array_fill( 0, count( $seen ), 0 ), $seen, 'The screen shows the administrator\'s own address, so the filter gets 0.' );
	}
}
