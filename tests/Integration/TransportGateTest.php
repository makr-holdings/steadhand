<?php
/**
 * Transport gate: who may reach /wp-json/steadhand/mcp at all.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Covers SPEC §3.2 and §13.1 item 5.
 */
final class TransportGateTest extends TestCase {

	private function initialize_status(): int {
		return $this->initialize_response()->get_status();
	}

	private function initialize_response(): \WP_REST_Response {
		return $this->mcp(
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
	}

	public function test_agent_with_connect_can_open_a_session(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assertSame( 200, $this->initialize_status() );
	}

	public function test_anonymous_request_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->initialize_response();

		$this->assertSame( 401, $response->get_status() );
		// Core's code for a failed permission check on a logged-out request (see WP_REST_Server::check_authentication()).
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertFalse( ( new \Steadhand\Plugin() )->server()->can_connect(), 'Steadhand\'s own gate must refuse user 0.' );
	}

	public function test_administrator_is_not_connected_even_if_every_steadhand_capability_is_filtered_on(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$grant_all = static fn( array $allcaps ): array => array_merge( $allcaps, array_fill_keys( Capabilities::all(), true ) );
		add_filter( 'user_has_cap', $grant_all );

		try {
			$this->assertFalse( ( new \Steadhand\Plugin() )->server()->can_connect() );
		} finally {
			remove_filter( 'user_has_cap', $grant_all );
		}
	}

	public function test_administrator_without_connect_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 403, $this->initialize_status() );
	}

	public function test_agent_whose_connect_is_denied_at_user_level_is_refused(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::CONNECT => false ) ) );

		$this->assertSame( 403, $this->initialize_status() );
	}

	public function test_subscriber_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->initialize_status() );
	}
}
