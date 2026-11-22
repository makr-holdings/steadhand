<?php
/**
 * Transport gate: who may reach /wp-json/steadhand/mcp at all.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Roles;

/**
 * Covers SPEC §3.2, §13.1 item 5 and the dedicated-agent rule (SPEC §4.1, D14).
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

	/**
	 * Guards the is_agent() check in Server::can_connect(): an administrator who also holds the agent role
	 * (and so has steadhand_connect from the role defaults) is not a dedicated agent and must be refused.
	 */
	public function test_administrator_who_also_holds_the_agent_role_is_refused(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_role( Roles::ROLE );
		wp_set_current_user( $admin_id );

		$this->assertSame( 403, $this->initialize_status() );
		$this->assertFalse( ( new \Steadhand\Plugin() )->server()->can_connect() );
	}

	/**
	 * Guards the is_agent() check in Server::can_connect() against user-level grants: an administrator given
	 * steadhand_connect and steadhand_read directly, without the agent role, is refused.
	 */
	public function test_administrator_with_user_level_steadhand_grants_is_refused(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_cap( Capabilities::CONNECT, true );
		get_userdata( $admin_id )->add_cap( Capabilities::READ, true );
		wp_set_current_user( $admin_id );

		$this->assertSame( 403, $this->initialize_status() );
	}

	/**
	 * Same as above for a subscriber: user-level grants alone never make a non-agent a Steadhand user.
	 */
	public function test_subscriber_with_user_level_steadhand_grants_is_refused(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user_id )->add_cap( Capabilities::CONNECT, true );
		get_userdata( $user_id )->add_cap( Capabilities::READ, true );
		wp_set_current_user( $user_id );

		$this->assertSame( 403, $this->initialize_status() );
	}
}
