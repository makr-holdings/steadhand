<?php
/**
 * Pipeline input handling: defaults, validation and sanitising. The ability must never see unsanitised input.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\Pipeline;

/**
 * Covers CLAUDE.md "sanitize on input". run() and check() must hand the ability the same shapes.
 *
 * Core's WP_Ability::normalize_input() fills a top-level default only when the whole input is null, and
 * rest_is_integer() accepts canonical integer strings, so "5" is valid and becomes 5 when sanitised.
 */
final class PipelineSanitizeTest extends TestCase {

	public function test_run_gives_the_ability_sanitised_values_and_fills_defaults(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		( new Pipeline( new SpyLogger() ) )->run(
			$probe,
			array(
				'count' => '5',
				'flag'  => 'true',
				'title' => 'abc',
			)
		);

		$this->assertSame( 5, $probe->seen_in_execute['count'] );
		$this->assertTrue( $probe->seen_in_execute['flag'] );
		$this->assertSame( 'abc', $probe->seen_in_execute['title'] );
		$this->assertSame( 'draft', $probe->seen_in_execute['mode'] );
	}

	public function test_check_gives_authorize_object_the_same_shapes_as_run(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		( new Pipeline( new SpyLogger() ) )->check(
			$probe,
			array(
				'count' => '5',
				'flag'  => 'true',
				'title' => 'abc',
			)
		);

		$this->assertSame( 5, $probe->seen_in_authorize['count'] );
		$this->assertTrue( $probe->seen_in_authorize['flag'] );
		$this->assertSame( 'abc', $probe->seen_in_authorize['title'] );
		$this->assertSame( 'draft', $probe->seen_in_authorize['mode'] );
	}

	public function test_run_and_check_produce_identical_input_for_the_same_call(): void {
		wp_set_current_user( $this->make_agent() );
		$via_check = new ProbeAbility();
		$via_run   = new ProbeAbility();
		$input     = array(
			'count' => '7',
			'mode'  => 'pending',
		);

		( new Pipeline( new SpyLogger() ) )->check( $via_check, $input );
		( new Pipeline( new SpyLogger() ) )->run( $via_run, $input );

		$this->assertSame( $via_check->seen_in_authorize, $via_run->seen_in_execute );
	}

	public function test_a_defaulted_property_is_filled_in_check_as_well(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		$this->assertTrue( ( new Pipeline( new SpyLogger() ) )->check( $probe, array() ) );

		$this->assertSame( 'draft', $probe->seen_in_authorize['mode'] );
	}

	public function test_an_explicit_value_is_not_overwritten_by_the_default(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		( new Pipeline( new SpyLogger() ) )->run( $probe, array( 'mode' => 'pending' ) );

		$this->assertSame( 'pending', $probe->seen_in_execute['mode'] );
	}

	public function test_unknown_property_is_refused_by_run_and_check(): void {
		wp_set_current_user( $this->make_agent() );
		$logger = new SpyLogger();
		$probe  = new ProbeAbility();

		$run   = ( new Pipeline( $logger ) )->run( $probe, array( 'bogus' => 'x' ) );
		$check = ( new Pipeline( $logger ) )->check( $probe, array( 'bogus' => 'x' ) );

		$this->assertWPError( $run );
		$this->assertSame( 'steadhand_invalid_input', $run->get_error_code() );
		$this->assertWPError( $check );
		$this->assertSame( 'steadhand_invalid_input', $check->get_error_code() );
		$this->assertFalse( $probe->executed );
		$this->assertSame( array(), $probe->seen_in_authorize, 'authorize_object must not see refused input.' );
	}

	public function test_over_long_string_is_refused(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		$result = ( new Pipeline( new SpyLogger() ) )->run( $probe, array( 'title' => 'toolong' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertFalse( $probe->executed );
	}

	public function test_enum_violation_is_refused(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		$result = ( new Pipeline( new SpyLogger() ) )->run( $probe, array( 'mode' => 'published' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertFalse( $probe->executed );
	}

	public function test_a_non_integer_string_is_refused_by_run(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		$result = ( new Pipeline( new SpyLogger() ) )->run( $probe, array( 'count' => 'five' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
	}

	public function test_refused_input_is_logged_as_blocked_by_check(): void {
		wp_set_current_user( $this->make_agent() );
		$logger = new SpyLogger();

		( new Pipeline( $logger ) )->check( new ProbeAbility(), array( 'title' => 'toolong' ) );

		$this->assertCount( 1, $logger->entries );
		$this->assertSame( 'blocked', $logger->entries[0]->result );
	}
}
