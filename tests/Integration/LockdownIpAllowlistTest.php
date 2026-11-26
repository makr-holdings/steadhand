<?php
/**
 * Lockdown and the per-agent IP allowlist: an agent's Application Password is accepted only from a listed address (SPEC §4.1, D28).
 * A refused address gets exactly the response a wrong password gets. The negative tests come first.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\IpAllowlist;
use Steadhand\Lockdown;
use WP_REST_Request;

/**
 * Covers the IP rule of Lockdown (rule e, its second layer e2) and the transport gate (Server::can_connect(), layer 3).
 *
 * The class runs in its own process and defines REST_REQUEST, because core accepts an application password only on a REST request,
 * and the second layer needs the Steadhand route. The constant cannot be undefined, so no other class may share the process.
 *
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
final class LockdownIpAllowlistTest extends TestCase {

	/** An address on the lists in these tests. */
	private const LISTED = '198.51.100.7';

	/** An address that is not on them. */
	private const OTHER = '203.0.113.9';

	/** The MCP endpoint route. */
	private const MCP = '/steadhand/mcp';

	/** A REST route that is not the Steadhand endpoint. */
	private const USERS_ME = '/wp/v2/users/me';

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

	/**
	 * The errors-hook callback this test detached, with its priority, so tearDown can put it back.
	 *
	 * @var array{0: int, 1: callable}|null
	 */
	private ?array $detached = null;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'REST_REQUEST' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's own constant.
			define( 'REST_REQUEST', true );
		}
		$GLOBALS['wp']->query_vars['rest_route'] = self::MCP;

		foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED', 'REQUEST_METHOD' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Saved and restored, never printed.
			$this->server_before[ $key ] = $_SERVER[ $key ] ?? null;
		}
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->on( 'application_password_is_api_request', '__return_true' );
		$this->on( 'wp_is_application_passwords_available', '__return_true' );
		$this->on(
			'steadhand_agent_ip_refused',
			function ( ...$args ): void {
				$this->refusals[] = $args;
			},
			10
		);

		$this->reset_report_guard();
	}

	protected function tearDown(): void {
		foreach ( $this->added as $added ) {
			remove_filter( $added[0], $added[1], $added[2] );
		}
		$this->added = array();

		if ( null !== $this->detached ) {
			add_action( 'wp_authenticate_application_password_errors', $this->detached[1], $this->detached[0], 2 );
			$this->detached = null;
		}

		foreach ( $this->server_before as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		unset( $GLOBALS['wp']->query_vars['rest_route'], $GLOBALS['HTTP_RAW_POST_DATA'] );
		wp_set_current_user( 0 );
		$this->reset_report_guard();

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
	 * Clears Lockdown's once-per-request guard. Each request resets it when Plugin::boot() runs; the tests run several requests in one process.
	 */
	private function reset_report_guard(): void {
		$property = new \ReflectionProperty( Lockdown::class, 'ip_refusal_reported' );
		$property->setValue( null, false );
	}

	/**
	 * Removes Lockdown's errors-hook callback, as a plugin that removed it would. tearDown puts it back.
	 */
	private function detach_errors_hook(): void {
		global $wp_filter;

		$hook = $wp_filter['wp_authenticate_application_password_errors'] ?? null;
		foreach ( $hook->callbacks ?? array() as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Lockdown ) {
					remove_action( 'wp_authenticate_application_password_errors', $callback['function'], $priority );
					$this->detached = array( $priority, $callback['function'] );
					return;
				}
			}
		}

		$this->fail( 'The errors-hook callback of Lockdown was not registered.' );
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

	/**
	 * Serves one request through WP_REST_Server::serve_request(), as a real request is served, with the application-password credentials
	 * the request carries. Returns what the server would send: the status, the data and the headers.
	 *
	 * @param string      $method   HTTP method.
	 * @param string      $route    REST route.
	 * @param string      $login    The login the request carries.
	 * @param string      $password The password the request carries.
	 * @param string|null $body     The request body, or null for none.
	 * @return array{status: int, data: mixed, headers: array<string, string>}
	 */
	private function serve( string $method, string $route, ?string $login, ?string $password, ?string $body = null ): array {
		$_SERVER['REQUEST_METHOD'] = $method;
		if ( null !== $login ) {
			$_SERVER['PHP_AUTH_USER'] = $login;
			$_SERVER['PHP_AUTH_PW']   = (string) $password;
		}
		// Core reads the request body from this global when it is set (rest_api.php, get_raw_data()); a test has no real body.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's own input for the request body, set for this request only.
		$GLOBALS['HTTP_RAW_POST_DATA']           = (string) $body;
		$GLOBALS['wp']->query_vars['rest_route'] = $route;

		$sent   = null;
		$served = static function ( $done, $result ) use ( &$sent ) {
			$sent = $result;
			return true;
		};
		add_filter( 'rest_pre_serve_request', $served, PHP_INT_MAX, 2 );
		// No user is cached, so the credentials are evaluated inside the request, as on a real request. A cached user would skip that.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Clears core's cached current user for this request only.
		$GLOBALS['current_user'] = null;

		try {
			rest_get_server()->serve_request( $route );
		} finally {
			remove_filter( 'rest_pre_serve_request', $served, PHP_INT_MAX );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Clears the request's user again, as core's own tests do.
			$GLOBALS['current_user'] = null;
			unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $GLOBALS['HTTP_RAW_POST_DATA'] );
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		$this->assertInstanceOf( \WP_REST_Response::class, $sent, 'The server must answer with a response object.' );

		return array(
			'status'  => $sent->get_status(),
			'data'    => $sent->get_data(),
			'headers' => $sent->get_headers(),
		);
	}

	/**
	 * A JSON-RPC initialize message, sent to the MCP endpoint.
	 *
	 * @return string
	 */
	private function initialize_body(): string {
		return (string) wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'steadhand-tests',
						'version' => '1',
					),
				),
			)
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

	/**
	 * The refusal is core's own wrong-password error, with the same code, text and data. A wrong password gives it too.
	 */
	public function test_a_refused_address_gives_core_s_wrong_password_error_with_the_same_code_text_and_data(): void {
		list( $id, $password ) = $this->agent();
		$user                  = new \WP_User( $id );

		$this->from( self::OTHER );
		$refused = wp_authenticate_application_password( null, $user->user_login, $password );
		$wrong   = wp_authenticate_application_password( null, $user->user_login, 'not-the-password' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'incorrect_password', $refused->get_error_code() );
		$this->assertSame( $wrong->get_error_code(), $refused->get_error_code() );
		$this->assertSame( $wrong->get_error_message(), $refused->get_error_message() );
		$this->assertSame( $wrong->get_error_data(), $refused->get_error_data() );
		$this->assertStringNotContainsString( self::OTHER, $refused->get_error_message() );
	}

	/**
	 * Over HTTP, a refused address and a wrong password are the same response: status, code, message, data and headers. Checked on the
	 * MCP endpoint and on a route that is not the endpoint.
	 */
	public function test_over_http_a_refused_address_and_a_wrong_password_send_the_same_response_on_every_route(): void {
		list( $id, $password ) = $this->agent();
		$login                 = ( new \WP_User( $id ) )->user_login;
		$this->from( self::OTHER );

		$cases = array(
			array( 'POST', self::MCP, $this->initialize_body() ),
			array( 'GET', self::USERS_ME, null ),
		);
		foreach ( $cases as list( $method, $route, $body ) ) {
			$wrong   = $this->serve( $method, $route, $login, 'not-the-password', $body );
			$refused = $this->serve( $method, $route, $login, $password, $body );

			$this->assertSame( 401, $wrong['status'], "$route: a wrong password is answered with 401." );
			$this->assertSame( $wrong, $refused, "$route: a refused address must look exactly like a wrong password." );
		}
	}

	/**
	 * On a route that is not the Steadhand endpoint, a listed agent's password does nothing: the request is answered as an anonymous one.
	 */
	public function test_a_listed_agent_on_another_route_is_answered_as_an_anonymous_visitor(): void {
		list( $id, $password ) = $this->agent();
		$login                 = ( new \WP_User( $id ) )->user_login;
		$this->from( self::LISTED );

		$anonymous     = $this->serve( 'GET', self::USERS_ME, null, null );
		$with_password = $this->serve( 'GET', self::USERS_ME, $login, $password );

		$this->assertSame( $anonymous, $with_password );
	}

	public function test_over_http_a_listed_agent_reaches_the_steadhand_endpoint(): void {
		list( $id, $password ) = $this->agent();
		$login                 = ( new \WP_User( $id ) )->user_login;
		$this->from( self::LISTED );

		$response = $this->serve( 'POST', self::MCP, $login, $password, $this->initialize_body() );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( array(), $this->refusals );
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
			$this->reset_report_guard();

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
			$this->reset_report_guard();
			add_filter( 'steadhand_client_ip', $callback, PHP_INT_MAX, 2 );

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

	/**
	 * The second layer (e2): an identity that came from another path is checked against the address again. The password check sees a
	 * listed address and the second check an unlisted one, so only the second layer can refuse.
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

	/**
	 * With the errors hook removed, the second layer alone refuses a listed-address-less password, and over HTTP the answer is still core's
	 * wrong-password response.
	 */
	public function test_with_the_errors_hook_removed_the_second_layer_still_sends_the_wrong_password_response(): void {
		list( $id, $password ) = $this->agent();
		$login                 = ( new \WP_User( $id ) )->user_login;
		$this->from( self::OTHER );
		$wrong = $this->serve( 'POST', self::MCP, $login, 'not-the-password', $this->initialize_body() );

		$this->detach_errors_hook();
		$refused = $this->serve( 'POST', self::MCP, $login, $password, $this->initialize_body() );

		$this->assertSame( $wrong, $refused );
		$this->assertSame( array( array( $id, self::OTHER ) ), $this->refusals );
	}

	/**
	 * Layer 3: an identity set by other code (wp_set_current_user()) is refused at the Steadhand endpoint from an unlisted address, with the
	 * refusal Server::can_connect() already gives. The same response as an agent that is disabled.
	 */
	public function test_layer_three_refuses_an_identity_set_by_other_code_from_an_unlisted_address(): void {
		list( $id )          = $this->agent();
		list( $disabled_id ) = $this->agent();
		get_userdata( $disabled_id )->remove_cap( \Steadhand\Capabilities::CONNECT );
		$this->from( self::OTHER );

		wp_set_current_user( $id );
		$refused = $this->mcp( 'initialize' );
		wp_set_current_user( $disabled_id );
		$disabled = $this->mcp( 'initialize' );

		$this->assertSame( $disabled->get_status(), $refused->get_status() );
		$this->assertSame( $disabled->get_data(), $refused->get_data(), 'The refusal is the one can_connect() already gives.' );
		$this->assertSame( array( array( $id, self::OTHER ) ), $this->refusals );
	}

	public function test_layer_three_refuses_even_with_the_errors_hook_removed(): void {
		list( $id ) = $this->agent();
		$this->from( self::OTHER );
		$this->detach_errors_hook();

		wp_set_current_user( $id );
		$response = $this->mcp( 'initialize' );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertSame( array( array( $id, self::OTHER ) ), $this->refusals );
	}

	public function test_layer_three_allows_a_listed_address_and_an_agent_without_a_list(): void {
		list( $listed ) = $this->agent();
		list( $open )   = $this->agent( null );
		$this->from( self::LISTED );

		wp_set_current_user( $listed );
		$this->assertSame( 200, $this->mcp( 'initialize' )->get_status() );

		$this->from( self::OTHER );
		wp_set_current_user( $open );
		$this->assertSame( 200, $this->mcp( 'initialize' )->get_status(), 'An agent with no list is unaffected.' );
		$this->assertSame( array(), $this->refusals );
	}

	public function test_layer_three_reports_at_most_once_per_request(): void {
		list( $id ) = $this->agent();
		$this->from( self::OTHER );

		wp_set_current_user( $id );
		$this->mcp( 'initialize' );
		$this->mcp( 'initialize' );

		$this->assertCount( 1, $this->refusals );
	}

	public function test_a_throwing_listener_does_not_change_the_layer_three_refusal(): void {
		list( $id ) = $this->agent();
		$this->from( self::OTHER );
		$this->on(
			'steadhand_agent_ip_refused',
			static function (): void {
				throw new \RuntimeException( 'The alert could not be sent.' );
			},
			1
		);

		wp_set_current_user( $id );
		$this->assertNotSame( 200, $this->mcp( 'initialize' )->get_status() );
	}
}
