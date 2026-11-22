<?php
/**
 * The pipeline context flag: set only while a Steadhand call is authorizing or executing.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers the D6 security requirement (SPEC §14.1): the metacap mapping must apply only inside a Steadhand call.
 * The flag is driven only through real pipeline calls, never set directly.
 */
final class PipelineContextTest extends TestCase {

	public function test_flag_is_false_before_any_call(): void {
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_flag_is_false_after_a_successful_run(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		$result = ( new Pipeline( new SpyLogger() ) )->run( $probe, array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $probe->executed );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_flag_is_false_after_a_refused_run(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );

		$result = ( new Pipeline( new SpyLogger() ) )->run( new ProbeAbility(), array() );

		$this->assertWPError( $result );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_flag_is_false_after_an_invalid_input_run(): void {
		wp_set_current_user( $this->make_agent() );

		$result = ( new Pipeline( new SpyLogger() ) )->run( new ProbeAbility(), array( 'unknown' => 1 ) );

		$this->assertWPError( $result );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_flag_is_false_after_an_ability_that_throws(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility(
			Capabilities::READ,
			static function (): array {
				throw new \RuntimeException( 'Ability failed.' );
			}
		);

		$result = ( new Pipeline( new SpyLogger() ) )->run( $probe, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_error', $result->get_error_code() );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_flag_is_false_after_a_run_whose_logger_fails(): void {
		wp_set_current_user( $this->make_agent() );
		// A successful read is not logged by default, so the logger is only reached when the read is opted in.
		add_filter( 'steadhand_log_read_calls', '__return_true' );

		$result = ( new Pipeline( new SpyLogger( false ) ) )->run( new ProbeAbility(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_flag_is_true_inside_authorize_object_and_execute(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		( new Pipeline( new SpyLogger() ) )->run( $probe, array() );

		$this->assertTrue( $probe->running_in_authorize );
		$this->assertTrue( $probe->running_in_execute );
	}

	public function test_flag_is_true_inside_authorize_object_during_a_permission_check(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new ProbeAbility();

		$this->assertTrue( ( new Pipeline( new SpyLogger() ) )->check( $probe, array() ) );

		$this->assertTrue( $probe->running_in_authorize );
		$this->assertNull( $probe->running_in_execute, 'A permission check must not execute the ability.' );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_flag_is_cleared_when_authorize_object_throws_during_a_permission_check(): void {
		wp_set_current_user( $this->make_agent() );
		$probe                     = new ProbeAbility();
		$probe->throw_in_authorize = true;

		try {
			( new Pipeline( new SpyLogger() ) )->check( $probe, array() );
			$this->fail( 'The exception must reach the caller.' );
		} catch ( \RuntimeException ) {
			$this->assertFalse( Pipeline::is_running() );
		}
	}

	public function test_nested_run_keeps_the_outer_flag_until_the_outer_call_finishes(): void {
		wp_set_current_user( $this->make_agent() );
		$logger = new SpyLogger();
		$inner  = new ProbeAbility();
		$outer  = new ProbeAbility(
			Capabilities::READ,
			static function () use ( $logger, $inner ): array {
				( new Pipeline( $logger ) )->run( $inner, array() );

				return array( 'running_after_inner' => Pipeline::is_running() );
			}
		);

		$result = ( new Pipeline( $logger ) )->run( $outer, array() );

		$this->assertTrue( $result['running_after_inner'], 'A nested call must not clear the outer call\'s flag.' );
		$this->assertTrue( $inner->running_in_execute );
		$this->assertFalse( Pipeline::is_running() );
	}
	/**
	 * A wp_die() or exit inside an ability can run shutdown hooks while the call's depth counter is still set. Those
	 * hooks must see the flag cleared, so the mapping does not stay live for them.
	 *
	 * Runs in its own process: do_action( 'shutdown' ) makes did_action( 'shutdown' ) non-zero for the rest of the
	 * process, and the other tests would then see a shutting-down site.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_flag_is_false_inside_shutdown_hooks_that_run_during_a_call(): void {
		// WordPress's own shutdown callbacks (output buffer flushing, for one) would run here too. This process is
		// ours alone, so they are removed first.
		remove_all_actions( 'shutdown' );
		wp_set_current_user( $this->make_agent() );
		$during_shutdown = null;
		$inside_call     = null;
		$hook            = static function () use ( &$during_shutdown ): void {
			$during_shutdown = Pipeline::is_running();
		};
		add_action( 'shutdown', $hook );

		$probe = new ProbeAbility(
			Capabilities::READ,
			static function () use ( &$inside_call ): array {
				$inside_call = Pipeline::is_running();
				do_action( 'shutdown' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's shutdown hook, fired on purpose.

				return array();
			}
		);

		try {
			( new Pipeline( new SpyLogger() ) )->run( $probe, array() );
		} finally {
			remove_action( 'shutdown', $hook );
		}

		$this->assertTrue( $inside_call, 'Control: the flag is set inside the ability.' );
		$this->assertFalse( $during_shutdown, 'Shutdown hooks must not see the call as running.' );
	}
}
