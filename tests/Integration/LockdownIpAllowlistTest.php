<?php
/**
 * Lockdown and the per-agent IP allowlist: an agent's Application Password is accepted only from a listed address (SPEC §4.1, D28).
 * The negative tests come first.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\IpAllowlist;
use Steadhand\Lockdown;
use WP_REST_Request;

/**
 * Covers the IP rule of Lockdown (rule e and its second layer, e2) and the refusal's text, code and status.
 *
 * The class runs in its own process and defines REST_REQUEST, because core accepts an application password only on a REST request,
 * and the second layer of Lockdown needs the Steadhand route. The constant cannot be undefined, so no other class may share the process.
 *
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
final class LockdownIpAllowlistTest extends TestCase {

	/** An address on the lists in these tests. */
	private const LISTED = '198.51.100.7';

	/** An address that is not on them. */
	private const OTHER = '203.0.113.9';

	/** The generic sign-in refusal, shared with refuse_agent_login(). */
	private const LOGIN_CODE = 'steadhand_agent_login_blocked';

	/** The generic REST refusal every blocked request gets. */
	private const REST_CODE = 'steadhand_agent_endpoint_only';

	/**
	 * The $_SERVER keys this test may change, with their values before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $server_before = array();

	/**
	 * Filters and actions this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * Calls to the steadhand_agent_ip_refused action, as the listener received them.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $refusals = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'REST_REQUEST' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's own constant.
			define( 'REST_REQUEST', true );
		}
		$GLOBALS['wp']->query_vars['rest_route'] = '/steadhand/mcp';

		foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Saved and restored, never printed.
			$this->server_before[ $key ] = $_SERVER[ $key ] ?? null;
		}

		$this->on( 'application_password_is_api_request', '__return_true' );
		$this->on( 'wp_is_application_passwords_available', '__return_true' );

		$this->on(
			'steadhand_agent_ip_refused',
			function ( ...$args ): void {
				$this->refusals[] = $args;
			},
			10
		);

		Lockdown::forget_refused_request();
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

		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		wp_set_current_user( 0 );
		Lockdown::forget_refused_request();

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
		$accepted = 'steadhand_client_ip' === $hook ? 2 : 10;
		add_filter( $hook, $callback, $priority, $accepted );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * A dedicated agent, with its list set when one is given, and one Application Password.
	 *
	 * @param string[]|null $entries The list, or null for none.
	 * @return array{0: int, 1: string} The agent's ID and its plain password.
	 */
	private function agent( ?array $entries = array( self::LISTED ) ): array {
		$id = $this->make_agent();
		if ( null !== $entries ) {
			update_user_meta( $id, IpAllowlist::META_KEY, $entries );
		}

		list( $password ) = $this->app_password_for( $id, 'Claude Desktop' );

		return array( $id, $password );
	}

	/**
	 * Sets the client address that REMOTE_ADDR reports for the rest of the test.
	 *
	 * @param string|null $ip The address, or null for none.
	 */
	private function from( ?string $ip ): void {
		if ( null === $ip ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $ip;
		}
	}

	/**
	 * The stored state of an application password: its last-used time and address.
	 *
	 * @param int $user_id The user.
	 * @return array{last_used: mixed, last_ip: mixed}
	 */
	private function usage( int $user_id ): array {
		$item = \WP_Application_Passwords::get_user_application_passwords( $user_id )[0];

		return array(
			'last_used' => $item['last_used'],
			'last_ip'   => $item['last_ip'],
		);
	}

	public function test_a_listed_address_authenticates_the_agent(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::LISTED );

		$this->assertSame( $id, $this->authenticate_with_app_password( new \WP_User( $id ), $password ) );
		$this->assertSame( array(), $this->refusals );
	}

	public function test_an_unlisted_address_is_refused_writes_nothing_and_fires_the_action_once(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );
		$before = get_user_meta( $id );

		$result = $this->authenticate_with_app_password( new \WP_User( $id ), $password );

		$this->assertEmpty( $result, 'An unlisted address must not authenticate the agent.' );
		$this->assertSame(
			array(
				'last_used' => null,
				'last_ip'   => null,
			),
			$this->usage( $id ),
			'A refused attempt must not record the password as used, or the address it came from.'
		);
		$this->assertSame( $before, get_user_meta( $id ), 'Steadhand must not write to the database on a refusal.' );
		$this->assertSame( array( array( $id, self::OTHER ) ), $this->refusals, 'The action fires once, with the agent and the address seen.' );
	}

	public function test_the_sign_in_refusal_is_the_generic_one_and_does_not_name_the_rule(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );
		$user = new \WP_User( $id );

		$result = wp_authenticate_application_password( null, $user->user_login, $password );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( self::LOGIN_CODE, $result->get_error_code(), 'The refusal must be the same code as the password sign-in refusal.' );
		$this->assertSame( 'This account cannot sign in here.', $result->get_error_message() );
		$this->assertStringNotContainsString( self::OTHER, $result->get_error_message() );
	}

	public function test_a_request_without_a_remote_addr_is_refused(): void {
		list( $id, $password ) = $this->agent();
		$this->from( null );

		$this->assertEmpty( $this->authenticate_with_app_password( new \WP_User( $id ), $password ) );
		$this->assertSame( array( array( $id, '' ) ), $this->refusals, 'With no address, the action gets an empty string.' );
	}

	public function test_a_corrupt_list_refuses_even_a_listed_address(): void {
		list( $id, $password ) = $this->agent();
		update_user_meta( $id, IpAllowlist::META_KEY, 'junk' );
		$this->from( self::LISTED );

		$this->assertEmpty( $this->authenticate_with_app_password( new \WP_User( $id ), $password ) );
		$this->assertSame( array( array( $id, self::LISTED ) ), $this->refusals );
	}

	public function test_forwarded_address_headers_cannot_stand_in_for_remote_addr(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );

		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED' ) as $header ) {
			$_SERVER[ $header ] = self::LISTED;
			Lockdown::forget_refused_request();

			$this->assertEmpty( $this->authenticate_with_app_password( new \WP_User( $id ), $password ), "$header must not be trusted." );
			unset( $_SERVER[ $header ] );
		}

		$this->assertCount( 4, $this->refusals, 'Each header attempt is one refusal, with the REMOTE_ADDR as the address seen.' );
		$this->assertSame( self::OTHER, $this->refusals[0][1] );
	}

	public function test_the_client_filter_can_supply_a_listed_address(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );
		$seen = array();
		$this->on(
			'steadhand_client_ip',
			static function ( $ip, $user_id ) use ( &$seen ) {
				$seen[] = array( $ip, $user_id );
				return self::LISTED;
			},
			PHP_INT_MAX
		);

		$this->assertSame( $id, $this->authenticate_with_app_password( new \WP_User( $id ), $password ) );
		$this->assertNotEmpty( $seen );
		$this->assertSame( array_fill( 0, count( $seen ), array( self::OTHER, $id ) ), $seen, 'The filter receives the REMOTE_ADDR and the agent ID, on each check.' );
	}

	public function test_a_client_filter_result_that_is_unusable_refuses_the_agent(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );

		$results = array(
			'garbage' => static fn() => 'garbage',
			'array'   => static fn() => array( self::LISTED ),
			'throws'  => static function (): string {
				throw new \RuntimeException( 'The proxy rule broke.' );
			},
		);

		foreach ( $results as $name => $callback ) {
			$this->added[] = array( 'steadhand_client_ip', $callback, PHP_INT_MAX );
			add_filter( 'steadhand_client_ip', $callback, PHP_INT_MAX, 2 );
			Lockdown::forget_refused_request();

			$this->assertEmpty( $this->authenticate_with_app_password( new \WP_User( $id ), $password ), "A filter that returns $name must refuse." );
			remove_filter( 'steadhand_client_ip', $callback, PHP_INT_MAX );
		}
	}

	public function test_an_agent_without_a_list_is_unaffected_and_the_filter_is_not_called(): void {
		list( $id, $password ) = $this->agent( null );
		$this->from( self::OTHER );
		$calls = 0;
		$this->on(
			'steadhand_client_ip',
			static function ( $ip ) use ( &$calls ) {
				++$calls;
				return $ip;
			},
			PHP_INT_MAX
		);

		$this->assertSame( $id, $this->authenticate_with_app_password( new \WP_User( $id ), $password ) );
		$this->assertSame( 0, $calls, 'The client address is not looked up for an agent with no list.' );
		$this->assertSame( array(), $this->refusals );
	}

	public function test_an_administrators_application_password_is_unaffected_from_any_address(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $admin_id, IpAllowlist::META_KEY, array( self::LISTED ) );
		list( $password ) = $this->app_password_for( $admin_id, 'Admin tool' );
		$this->from( self::OTHER );

		$this->assertSame( $admin_id, $this->authenticate_with_app_password( new \WP_User( $admin_id ), $password ), 'An administrator is not an agent and has no list.' );
		$this->assertSame( array(), $this->refusals );
	}

	public function test_the_errors_hook_adds_no_error_for_an_administrator_or_an_agent_without_a_list(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $admin_id, IpAllowlist::META_KEY, array( self::LISTED ) );
		list( , $admin_uuid ) = $this->app_password_for( $admin_id, 'Admin tool' );
		list( $agent_id )     = $this->agent( null );
		$this->from( self::OTHER );

		foreach ( array( new \WP_User( $admin_id ), new \WP_User( $agent_id ) ) as $user ) {
			$error = new \WP_Error();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			do_action( 'wp_authenticate_application_password_errors', $error, $user, array( 'uuid' => $admin_uuid ), 'password' );

			$this->assertFalse( $error->has_errors(), 'The IP rule must add no error for ' . $user->user_login . '.' );
		}
	}

	public function test_a_throwing_listener_does_not_turn_the_refusal_into_a_pass(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );
		$this->on(
			'steadhand_agent_ip_refused',
			static function (): void {
				throw new \RuntimeException( 'The alert could not be sent.' );
			},
			1
		);

		$this->assertEmpty( $this->authenticate_with_app_password( new \WP_User( $id ), $password ) );
	}

	public function test_the_action_fires_at_most_once_per_request(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );

		$this->authenticate_with_app_password( new \WP_User( $id ), $password );
		$this->authenticate_with_app_password( new \WP_User( $id ), $password );

		$this->assertCount( 1, $this->refusals );
	}

	public function test_the_action_receives_only_the_user_id_and_the_address(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );

		$this->authenticate_with_app_password( new \WP_User( $id ), $password );

		$this->assertSame( array( $id, self::OTHER ), $this->refusals[0] );
		$this->assertNotContains( $password, $this->refusals[0] );
		$this->assertNotContains( self::LISTED, $this->refusals[0], 'The list is never passed.' );
	}

	public function test_a_refused_agent_gets_the_same_refusal_on_the_steadhand_endpoint_as_on_any_other_route(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );
		$this->authenticate_with_app_password( new \WP_User( $id ), $password );

		$mcp  = rest_do_request( new WP_REST_Request( 'POST', '/steadhand/mcp' ) );
		$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) );

		$this->assertSame( 403, $mcp->get_status() );
		$this->assertSame( 403, $rest->get_status() );
		$this->assertSame( $rest->get_data(), $mcp->get_data(), 'The endpoint must answer the same body as any other refused route.' );
		$this->assertSame( self::REST_CODE, ( (array) $mcp->get_data() )['code'] ?? null );
	}

	public function test_a_listed_agent_still_reaches_the_steadhand_endpoint(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::LISTED );
		wp_set_current_user( $this->authenticate_with_app_password( new \WP_User( $id ), $password ) );

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

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Guards the second layer (e2): an agent identity that came from another path is checked against the address again on a
	 * Steadhand REST request. The first check (the password) passes here and the second refuses, so only the second layer can refuse.
	 */
	public function test_the_second_layer_refuses_an_identity_that_came_from_another_path(): void {
		list( $id, $password ) = $this->agent();
		$this->from( self::OTHER );

		$calls = 0;
		add_filter(
			'steadhand_client_ip',
			static function ( $ip ) use ( &$calls ) {
				++$calls;
				return 1 === $calls ? self::LISTED : $ip;
			},
			PHP_INT_MAX,
			2
		);

		$this->assertEmpty( $this->authenticate_with_app_password( new \WP_User( $id ), $password ) );
		$this->assertSame( 2, $calls, 'The password check and the second layer each look at the address.' );
		$this->assertSame( array( array( $id, self::OTHER ) ), $this->refusals );
	}
}
