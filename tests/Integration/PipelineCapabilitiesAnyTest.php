<?php
/**
 * The caller check for abilities that accept more than one Steadhand capability (Ability::capabilities_any()).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * The caller must hold at least one listed capability. An unknown name is ignored, and an empty list falls back to the
 * ability's own capability. Covers SPEC §4.2 and §14.1 (an agent with publish alone must reach the publish path).
 */
final class PipelineCapabilitiesAnyTest extends TestCase {

	/**
	 * Runs the probe as the current user, through the pipeline's run() path, with a spy logger.
	 *
	 * @param string[] $declared What capabilities_any() returns.
	 * @return mixed
	 */
	private function run_probe( array $declared ): mixed {
		return ( new Pipeline( new SpyLogger() ) )->run( new CapabilitiesAnyProbe( $declared ), array() );
	}

	public function test_a_caller_holding_only_the_first_listed_capability_is_allowed(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::PUBLISH => false ) ) );

		$this->assertIsArray( $this->run_probe( array( Capabilities::WRITE_DRAFTS, Capabilities::PUBLISH ) ) );
	}

	public function test_a_caller_holding_only_the_second_listed_capability_is_allowed(): void {
		wp_set_current_user(
			$this->make_agent(
				array(
					Capabilities::WRITE_DRAFTS => false,
					Capabilities::PUBLISH      => true,
				)
			)
		);

		$this->assertIsArray( $this->run_probe( array( Capabilities::WRITE_DRAFTS, Capabilities::PUBLISH ) ) );
	}

	public function test_a_caller_holding_neither_listed_capability_is_refused_and_the_message_lists_both(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::WRITE_DRAFTS => false ) ) );

		$result = $this->run_probe( array( Capabilities::WRITE_DRAFTS, Capabilities::PUBLISH ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame(
			'This user needs the steadhand_write_drafts or steadhand_publish capability to call steadhand/probe-any.',
			$result->get_error_message()
		);
	}

	public function test_a_user_level_denial_of_both_capabilities_refuses_the_caller_even_though_the_role_grants_one(): void {
		// The agent role grants steadhand_write_drafts. A user-level false overrides that grant (SPEC §4.2).
		wp_set_current_user(
			$this->make_agent(
				array(
					Capabilities::WRITE_DRAFTS => false,
					Capabilities::PUBLISH      => false,
				)
			)
		);

		$this->assertWPError( $this->run_probe( array( Capabilities::WRITE_DRAFTS, Capabilities::PUBLISH ) ) );
	}

	public function test_a_listed_name_that_is_not_a_steadhand_capability_is_ignored(): void {
		wp_set_current_user(
			$this->make_agent(
				array(
					Capabilities::WRITE_DRAFTS => false,
					Capabilities::PUBLISH      => true,
				)
			)
		);

		$this->assertIsArray( $this->run_probe( array( 'steadhand_not_a_capability', Capabilities::PUBLISH ) ) );
	}

	public function test_an_unknown_name_is_never_granted_even_to_a_caller_who_holds_every_real_capability(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );

		$result = $this->run_probe( array( 'steadhand_not_a_capability' ) );

		$this->assertWPError( $result, 'Only unknown names are listed, so the list falls back to steadhand_read, which this caller lacks.' );
		$this->assertStringContainsString( 'steadhand_read', $result->get_error_message() );
	}

	public function test_an_empty_list_falls_back_to_the_ability_capability(): void {
		wp_set_current_user( $this->make_agent() );
		$this->assertIsArray( $this->run_probe( array() ), 'The caller holds steadhand_read, the fallback.' );

		wp_set_current_user(
			$this->make_agent(
				array(
					Capabilities::READ    => false,
					Capabilities::PUBLISH => true,
				)
			)
		);
		$result = $this->run_probe( array() );

		$this->assertWPError( $result, 'Holding publish does not satisfy the fallback, which is steadhand_read.' );
		$this->assertStringContainsString( 'steadhand_read', $result->get_error_message() );
	}

	public function test_check_uses_the_same_rule_as_run(): void {
		wp_set_current_user(
			$this->make_agent(
				array(
					Capabilities::WRITE_DRAFTS => false,
					Capabilities::PUBLISH      => true,
				)
			)
		);
		$pipeline = new Pipeline( new SpyLogger() );

		$this->assertTrue( $pipeline->check( new CapabilitiesAnyProbe( array( Capabilities::WRITE_DRAFTS, Capabilities::PUBLISH ) ), array() ) );
	}
}
