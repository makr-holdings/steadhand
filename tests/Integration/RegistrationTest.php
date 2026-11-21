<?php
/**
 * Registration: category, ability, MCP server, role and capabilities.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Roles;
use WP\MCP\Abilities\McpAbilityExposure;
use WP\MCP\Core\McpAdapter;

/**
 * Covers SPEC §13.1 item 2.
 */
final class RegistrationTest extends TestCase {

	public function test_ability_category_is_registered(): void {
		$this->assertTrue( wp_has_ability_category( 'steadhand' ) );
	}

	public function test_get_site_info_ability_is_registered_in_the_category(): void {
		$ability = wp_get_ability( 'steadhand/get-site-info' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'steadhand', $ability->get_category() );
	}

	public function test_ability_is_readonly_with_strict_schemas(): void {
		$ability = wp_get_ability( 'steadhand/get-site-info' );
		$meta    = $ability->get_meta();

		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
		$this->assertFalse( $ability->get_input_schema()['additionalProperties'] );
		$this->assertFalse( $ability->get_output_schema()['additionalProperties'] );
	}

	public function test_ability_is_not_exposed_outside_the_steadhand_server(): void {
		$ability = wp_get_ability( 'steadhand/get-site-info' );

		$this->assertFalse( McpAbilityExposure::is_public( $ability ), 'Must not reach the adapter default server.' );
		$this->assertFalse( (bool) ( $ability->get_meta()['show_in_rest'] ?? false ), 'Must not reach the core abilities REST API.' );
	}

	public function test_mcp_server_is_registered_at_the_steadhand_endpoint(): void {
		rest_get_server();
		$server = McpAdapter::instance()->get_server( 'steadhand' );

		$this->assertNotNull( $server );
		$this->assertSame( 'steadhand', $server->get_server_route_namespace() );
		$this->assertSame( 'mcp', $server->get_server_route() );
		$this->assertArrayHasKey( '/steadhand/mcp', rest_get_server()->get_routes() );
	}

	public function test_agent_role_has_only_read_and_the_default_steadhand_capabilities(): void {
		$role = get_role( Roles::ROLE );

		$this->assertNotNull( $role );
		$this->assertSame(
			array(
				'read'                   => true,
				'steadhand_connect'      => true,
				'steadhand_read'         => true,
				'steadhand_write_drafts' => true,
			),
			$role->capabilities
		);
	}

	public function test_no_other_role_is_given_steadhand_capabilities(): void {
		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( Roles::ROLE === $name ) {
				continue;
			}
			foreach ( Capabilities::all() as $capability ) {
				$this->assertFalse( $role->has_cap( $capability ), "$name must not have $capability" );
			}
		}
	}

	public function test_user_level_false_overrides_a_capability_the_role_grants(): void {
		$agent = get_userdata( $this->make_agent( array( Capabilities::READ => false ) ) );

		$this->assertFalse( Capabilities::user_has( $agent, Capabilities::READ ) );
		$this->assertTrue( Capabilities::user_has( $agent, Capabilities::CONNECT ) );
	}

	public function test_user_level_true_grants_a_capability_the_role_lacks(): void {
		$agent = get_userdata( $this->make_agent( array( Capabilities::PUBLISH => true ) ) );

		$this->assertTrue( Capabilities::user_has( $agent, Capabilities::PUBLISH ) );
	}

	public function test_administrator_does_not_hold_steadhand_capabilities(): void {
		$admin = get_userdata( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse( Capabilities::user_has( $admin, $capability ), $capability );
		}
	}

	public function test_uninstall_removes_the_role_and_strips_agents(): void {
		$agent_id = $this->make_agent( array( Capabilities::PUBLISH => true ) );
		\WP_Application_Passwords::create_new_application_password( $agent_id, array( 'name' => 'Test agent' ) );

		try {
			Roles::uninstall();
			$agent = get_userdata( $agent_id );

			$this->assertNull( get_role( Roles::ROLE ) );
			$this->assertSame( array(), $agent->roles );
			$this->assertSame( array(), array_intersect( array_keys( $agent->caps ), Capabilities::all() ) );
			$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $agent_id ) );
		} finally {
			Roles::install();
		}
	}

	public function test_uninstall_keeps_application_passwords_of_a_user_with_the_agent_role_and_another_role(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_role( Roles::ROLE );
		\WP_Application_Passwords::create_new_application_password( $admin_id, array( 'name' => 'Admin app' ) );

		try {
			Roles::uninstall();
			$admin = get_userdata( $admin_id );

			$this->assertNotContains( Roles::ROLE, $admin->roles );
			$this->assertContains( 'administrator', $admin->roles );
			$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $admin_id ) );
		} finally {
			Roles::install();
		}
	}

	public function test_registered_ability_is_marked_not_public_for_mcp(): void {
		$meta = wp_get_ability( 'steadhand/get-site-info' )->get_meta();

		$this->assertFalse( $meta['mcp']['public'] );
	}

	public function test_mcp_exposure_stays_closed_when_meta_public_is_set(): void {
		$meta = array_merge( wp_get_ability( 'steadhand/get-site-info' )->get_meta(), array( 'public' => true ) );

		$this->assertFalse( McpAbilityExposure::is_meta_public( $meta ) );
	}
}
