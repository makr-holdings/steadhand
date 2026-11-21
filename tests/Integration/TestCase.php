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
	 * Decodes a REST response body to plain arrays.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	protected function body( WP_REST_Response $response ): array {
		return json_decode( (string) wp_json_encode( $response->get_data() ), true );
	}
}
