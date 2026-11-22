<?php
/**
 * Shared helpers for integration tests.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Roles;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Base test case: agent users and MCP requests against the Steadhand endpoint.
 */
abstract class TestCase extends WP_UnitTestCase {

	protected const ROUTE = '/steadhand/mcp';
	protected const TOOL  = 'steadhand-get-site-info';

	/**
	 * Clears the state that core and these tests leave behind: the application password global that
	 * rest_get_authenticated_app_password() reads, and the filters the application password tests add.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_application_password_status'] );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Creates an application password for a user and returns the plain password and its UUID.
	 *
	 * @param int    $user_id User ID.
	 * @param string $name    Application password name.
	 * @return array{0: string, 1: string} Plain password, UUID.
	 */
	protected function app_password_for( int $user_id, string $name ): array {
		list( $password, $item ) = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );

		return array( $password, $item['uuid'] );
	}

	/**
	 * Runs core's determine_current_user chain for a request that carries an application password, as core does
	 * for a REST request. The application_password_is_api_request filter is set so that core accepts the password
	 * in every context. The remaining refusal is therefore Lockdown's doing, and not core's.
	 *
	 * @param \WP_User $user     The user the password belongs to.
	 * @param string   $password Plain application password.
	 * @return mixed The chain's result: a user ID, or 0 when Lockdown refused.
	 */
	protected function authenticate_with_app_password( \WP_User $user, string $password ): mixed {
		add_filter( 'application_password_is_api_request', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$_SERVER['PHP_AUTH_USER'] = $user->user_login;
		$_SERVER['PHP_AUTH_PW']   = $password;
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			return apply_filters( 'determine_current_user', false );
		} finally {
			unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		}
	}

	/**
	 * Creates an agent user, optionally with user-level capability overrides.
	 *
	 * @param array<string, bool> $overrides Capability => grant.
	 */
	protected function make_agent( array $overrides = array() ): int {
		$user_id = self::factory()->user->create( array( 'role' => Roles::ROLE ) );
		$user    = get_userdata( $user_id );
		foreach ( $overrides as $capability => $grant ) {
			$user->add_cap( $capability, $grant );
		}
		return $user_id;
	}

	/**
	 * Sends one JSON-RPC message to the Steadhand MCP endpoint as the current user.
	 *
	 * @param string               $method  JSON-RPC method.
	 * @param array<string, mixed> $params  Method params.
	 * @param string|null          $session MCP session ID, once initialized.
	 */
	protected function mcp( string $method, array $params = array(), ?string $session = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $session ) {
			$request->set_header( 'Mcp-Session-Id', $session );
			$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		}
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => (object) $params,
				)
			)
		);
		return rest_do_request( $request );
	}

	/**
	 * Opens an MCP session as the current user and returns its ID.
	 */
	protected function mcp_session(): string {
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
		return $response->get_headers()['Mcp-Session-Id'];
	}

	/**
	 * Calls a tool as the current user and returns the decoded JSON-RPC body.
	 *
	 * @param string               $tool      MCP tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	protected function call_tool( string $tool, array $arguments = array() ): array {
		$response = $this->mcp(
			'tools/call',
			array(
				'name'      => $tool,
				'arguments' => (object) $arguments,
			),
			$this->mcp_session()
		);
		$this->assertSame( 200, $response->get_status() );
		return $this->body( $response );
	}

	/**
	 * Sends one REST request through rest_do_request() as the current user.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $route  REST route, exactly as the request carries it.
	 * @param array<string, mixed>|null $body   JSON body, or null for none.
	 */
	protected function rest( string $method, string $route, ?array $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Decodes a REST response body to plain arrays.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	protected function body( WP_REST_Response $response ): array {
		return json_decode( (string) wp_json_encode( $response->get_data() ), true );
	}
}
