<?php
/**
 * Who counts as a dedicated Steadhand agent.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Agents;
use Steadhand\Roles;

/**
 * Covers the sole-role rule (SPEC §4.1, decision D14).
 *
 * Agents::is_agent() is the test that Server::can_connect(), Pipeline::authorize() and every
 * Lockdown rule rely on. The "administrator who also holds the agent role" case guards the
 * production line in Agents::is_agent() that compares the role list for exact equality with
 * array( Roles::ROLE ). An in_array() check would make that case pass, so the mutation run
 * in the M1a notes depends on this test.
 */
final class AgentsTest extends TestCase {

	public function test_is_agent_is_true_for_a_pure_agent(): void {
		$this->assertTrue( Agents::is_agent( get_userdata( $this->make_agent() ) ) );
	}

	public function test_is_agent_is_false_for_a_subscriber(): void {
		$user = get_userdata( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( Agents::is_agent( $user ) );
	}

	public function test_is_agent_is_false_for_an_administrator(): void {
		$user = get_userdata( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( Agents::is_agent( $user ) );
	}

	/**
	 * Guards the sole-role equality in Agents::is_agent(). The agent role is not the only role here, so the
	 * user must not count as an agent, even though it holds the agent role.
	 */
	public function test_is_agent_is_false_for_an_administrator_who_also_holds_the_agent_role(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_role( Roles::ROLE );

		$this->assertFalse( Agents::is_agent( new \WP_User( $admin_id ) ) );
	}

	public function test_is_agent_is_false_for_a_user_with_no_roles(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user_id )->remove_role( 'subscriber' );

		$this->assertFalse( Agents::is_agent( new \WP_User( $user_id ) ) );
	}

	public function test_is_agent_is_false_for_user_zero_and_for_a_missing_user(): void {
		$this->assertFalse( Agents::is_agent( new \WP_User( 0 ) ) );
		$this->assertFalse( Agents::is_agent( new \WP_User( 999999999 ) ) );
	}

	public function test_holds_agent_role_is_true_for_a_mixed_role_administrator(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_role( Roles::ROLE );

		$this->assertTrue( Agents::holds_agent_role( new \WP_User( $admin_id ) ) );
	}

	public function test_holds_agent_role_is_false_for_an_administrator_and_for_a_missing_user(): void {
		$admin = get_userdata( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( Agents::holds_agent_role( $admin ) );
		$this->assertFalse( Agents::holds_agent_role( new \WP_User( 0 ) ) );
	}
}
