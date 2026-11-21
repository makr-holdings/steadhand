<?php
/**
 * Missing dependencies: notice only, never fatal, nothing else registered.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Plugin;
use Steadhand\Requirements;

/**
 * Covers SPEC §13.1 item 1.
 */
final class RequirementsTest extends TestCase {

	public function test_detects_both_dependencies_in_the_test_environment(): void {
		$this->assertTrue( Requirements::detect()->are_met() );
	}

	public function test_missing_mcp_adapter_is_reported(): void {
		$requirements = new Requirements( true, false );

		$this->assertFalse( $requirements->are_met() );
		$this->assertSame( array( 'MCP Adapter' ), $requirements->missing() );
	}

	public function test_missing_abilities_api_is_reported(): void {
		$this->assertSame( array( 'Abilities API (WordPress 6.9+)' ), ( new Requirements( false, true ) )->missing() );
	}

	public function test_plugin_registers_nothing_but_a_notice_when_requirements_are_missing(): void {
		$plugin = new Plugin();

		$booted = $plugin->boot( new Requirements( false, false ) );

		$this->assertFalse( $booted );
		$this->assertFalse( has_action( 'wp_abilities_api_init', array( $plugin->registry(), 'register_abilities' ) ) );
		$this->assertFalse( has_action( 'mcp_adapter_init', array( $plugin->server(), 'register' ) ) );
	}

	public function test_notice_names_what_is_missing_for_users_who_can_fix_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new Requirements( true, false ) )->render_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'MCP Adapter', $html );
	}

	public function test_notice_is_hidden_from_users_who_cannot_manage_plugins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		( new Requirements( true, false ) )->render_notice();

		$this->assertSame( '', ob_get_clean() );
	}
}
