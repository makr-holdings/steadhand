<?php
/**
 * The write path of the pipeline: guardrails, log reservation, snapshot, execute, log completion (SPEC §7).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Abilities\GetSiteInfo;
use Steadhand\Capabilities;
use Steadhand\Guardrails\Engine;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rules\BlockMarkup;
use Steadhand\Guardrails\Rules\ContentLimits;
use Steadhand\Guardrails\Rules\EditLock;
use Steadhand\Guardrails\Rules\RateLimit;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers the write path. A probe write ability records what it sees, and a SpyLogger records the order of the calls.
 */
final class PipelineWriteTest extends LogTestCase {

	private const GENERIC = 'The ability failed to complete. Try again, or check the site error log.';

	/** The text of a write whose execute() threw: it may have been partly applied (S7). */
	private const WRITE_THROW = 'The change could not be completed and may have been partly applied. Check the result before you continue.';

	private const PENDING = 'The call started, but no outcome was recorded.';

	/**
	 * Runs a write as the given agent, through a pipeline with the given logger and engine.
	 *
	 * @param WriteProbeAbility $probe  The write.
	 * @param mixed             $input  Input.
	 * @param object            $logger Logger: a SpyLogger or a DbLogger.
	 * @param Engine|null       $engine Guardrails; null for none.
	 * @return mixed
	 */
	private function write( Ability $probe, mixed $input, object $logger, ?Engine $engine = null ): mixed {
		return ( new Pipeline( $logger, $engine ) )->run( $probe, $input );
	}

	/**
	 * A guardrail rule that allows everything and records itself on the spy.
	 *
	 * @param SpyLogger $spy Spy.
	 * @param string    $id  Rule ID.
	 */
	private function allowing( SpyLogger $spy, string $id = 'steadhand/recorder' ): FakeRule {
		return new FakeRule( $id, static fn(): Result => Result::allow(), $spy );
	}

	public function test_the_stages_run_in_order_guardrails_then_reservation_then_snapshot_then_execute_then_completion(): void {
		wp_set_current_user( $this->make_agent() );
		$spy    = new SpyLogger();
		$engine = new Engine(
			array( $this->allowing( $spy, 'steadhand/first' ) ),
			array( $this->allowing( $spy, 'steadhand/last' ) )
		);
		$probe  = new WriteProbeAbility( Capabilities::WRITE_DRAFTS, $spy );

		$result = $this->write( $probe, array( 'title' => 'Hello' ), $spy, $engine );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'rule:steadhand/first', 'rule:steadhand/last', 'begin', 'snapshot', 'execute', 'complete' ),
			$spy->events,
			'A write runs its stages in this order, and never calls log().'
		);
	}

	public function test_a_guardrail_block_means_the_write_does_not_run_and_one_blocked_row_is_stored(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$engine = new Engine(
			array( new FakeRule( 'steadhand/refuse', static fn(): Result => Result::block( 'Not this one.' ) ) ),
			array()
		);
		$probe  = new WriteProbeAbility();

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger(), $engine );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( 'Not this one.', $result->get_error_message() );
		$this->assertFalse( $probe->executed );
		$this->assertSame( 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_BLOCKED, $row['result'] );
		$this->assertSame( 'steadhand/refuse: blocked', $row['guardrail_notes'] );
	}

	public function test_a_guardrail_block_never_reserves_a_row(): void {
		wp_set_current_user( $this->make_agent() );
		$spy    = new SpyLogger();
		$engine = new Engine( array( new FakeRule( 'steadhand/refuse', static fn(): Result => Result::block( 'No.' ), $spy ) ), array() );

		$this->write( new WriteProbeAbility( Capabilities::WRITE_DRAFTS, $spy ), array(), $spy, $engine );

		$this->assertSame( array( 'rule:steadhand/refuse', 'log' ), $spy->events, 'The refusal is stored with log(), not begin().' );
	}

	public function test_when_the_reservation_fails_the_write_is_not_performed(): void {
		wp_set_current_user( $this->make_agent() );
		$this->make_inserts_fail();
		$probe = new WriteProbeAbility();

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
		$this->assertFalse( $probe->executed, 'A write that cannot be recorded must not run.' );
	}

	public function test_when_the_reservation_fails_with_a_spy_the_write_is_not_performed(): void {
		wp_set_current_user( $this->make_agent() );
		$spy   = new SpyLogger( true, false );
		$probe = new WriteProbeAbility( Capabilities::WRITE_DRAFTS, $spy );

		$result = $this->write( $probe, array(), $spy );

		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
		$this->assertFalse( $probe->executed );
		$this->assertSame( array( 'begin' ), $spy->events );
	}

	public function test_a_snapshot_that_throws_completes_the_row_as_an_error_and_does_not_execute(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                    = new WriteProbeAbility();
		$probe->throw_in_snapshot = true;

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_error', $result->get_error_code() );
		$this->assertSame( self::GENERIC, $result->get_error_message() );
		$this->assertFalse( $probe->executed );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( self::GENERIC, $row['message'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	public function test_an_execute_that_throws_is_logged_as_an_error_and_its_text_is_not_leaked(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                   = new WriteProbeAbility();
		$probe->throw_in_execute = true;

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_error', $result->get_error_code() );
		$this->assertSame( self::WRITE_THROW, $result->get_error_message() );
		$this->assertStringNotContainsString( 'secret-probe-text', (string) wp_json_encode( $result->get_error_data() ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( self::WRITE_THROW, $row['message'] );
		$this->assertStringNotContainsString( 'secret-probe-text', $row['message'] );
	}

	public function test_an_execute_that_returns_a_wp_error_is_logged_as_an_error_with_that_message(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe             = new WriteProbeAbility();
		$probe->on_execute = static fn(): \WP_Error => new \WP_Error( 'probe_failed', 'Probe failed.', array( 'status' => 500 ) );

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertWPError( $result );
		$this->assertSame( 'probe_failed', $result->get_error_code() );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( 'Probe failed.', $row['message'] );
	}

	public function test_a_failed_completion_returns_steadhand_log_incomplete_because_the_write_happened(): void {
		wp_set_current_user( $this->make_agent() );
		$spy   = new SpyLogger( true, true, false );
		$probe = new WriteProbeAbility( Capabilities::WRITE_DRAFTS, $spy );

		$result = $this->write( $probe, array( 'title' => 'x' ), $spy );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_incomplete', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertTrue( $probe->executed, 'The write ran; only its outcome could not be recorded.' );
	}

	public function test_a_fixed_input_reaches_execute_and_the_row_says_fixed(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$engine = new Engine(
			array( new FakeRule( 'steadhand/shorten', static fn(): Result => Result::fix( array( 'title' => 'Short' ), 'Shortened the title.' ) ) ),
			array()
		);
		$probe  = new WriteProbeAbility();

		$result = $this->write( $probe, array( 'title' => 'A much longer title' ), new DbLogger(), $engine );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'title' => 'Short' ), $probe->seen_in_execute, 'execute() gets the final input.' );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_FIXED, $row['result'] );
		$this->assertSame( 'steadhand/shorten: Shortened the title.', $row['guardrail_notes'] );
		$this->assertSame( array( 'steadhand/shorten: Shortened the title.' ), $result['guardrail_notes'] );
	}

	public function test_a_fix_that_the_object_check_refuses_blocks_the_write(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$engine              = new Engine(
			array( new FakeRule( 'steadhand/rewrite', static fn(): Result => Result::fix( array( 'title' => 'refused title' ), 'Rewrote.' ) ) ),
			array()
		);
		$probe               = new WriteProbeAbility();
		$probe->refuse_title = 'refused title';

		$result = $this->write( $probe, array( 'title' => 'fine' ), new DbLogger(), $engine );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( 'A guardrail changed this call into one that is not allowed, so it was refused.', $result->get_error_message() );
		$this->assertFalse( $probe->executed );
		$this->assertSame( Entry::RESULT_BLOCKED, $this->latest_row( $agent )['result'] );
	}

	public function test_guardrail_notes_are_added_to_an_array_output_of_a_write(): void {
		wp_set_current_user( $this->make_agent() );
		$engine = new Engine(
			array( new FakeRule( 'steadhand/noisy', static fn(): Result => Result::warn( 'Looks odd.' ) ) ),
			array()
		);

		$result = $this->write( new WriteProbeAbility(), array( 'title' => 'x' ), new SpyLogger(), $engine );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'steadhand/noisy: Looks odd.' ), $result['guardrail_notes'] );
	}

	public function test_guardrail_notes_are_an_empty_list_when_no_rule_said_anything(): void {
		wp_set_current_user( $this->make_agent() );

		$result = $this->write( new WriteProbeAbility(), array( 'title' => 'x' ), new SpyLogger() );

		$this->assertSame( array(), $result['guardrail_notes'] );
	}

	public function test_is_writing_is_true_inside_a_write_s_snapshot_and_execute(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = new WriteProbeAbility();

		$this->write( $probe, array( 'title' => 'x' ), new SpyLogger() );

		$this->assertTrue( $probe->writing_in_snapshot );
		$this->assertTrue( $probe->writing_in_execute );
		$this->assertTrue( $probe->running_in_execute, 'The metacap mapping is also on for a write.' );
		$this->assertFalse( Pipeline::is_writing(), 'And off again afterwards.' );
	}

	public function test_is_writing_is_false_inside_a_read_ability_s_execute(): void {
		wp_set_current_user( $this->make_agent() );
		$seen  = null;
		$probe = new ProbeAbility(
			Capabilities::READ,
			static function () use ( &$seen ): array {
				$seen = Pipeline::is_writing();
				return array();
			}
		);

		$this->write( $probe, array(), new SpyLogger() );

		$this->assertFalse( $seen );
	}

	public function test_is_writing_is_false_after_every_exit_path(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$exits = array();

		$exits[] = fn() => $this->write( new WriteProbeAbility(), array( 'title' => 'x' ), new SpyLogger() );

		$blocking = new Engine( array( new FakeRule( 'steadhand/no', static fn(): Result => Result::block( 'No.' ) ) ), array() );
		$exits[]  = fn() => $this->write( new WriteProbeAbility(), array(), new SpyLogger(), $blocking );

		$exits[] = function () {
			$probe                    = new WriteProbeAbility();
			$probe->throw_in_snapshot = true;
			return $this->write( $probe, array(), new SpyLogger() );
		};

		$exits[] = function () {
			$probe                   = new WriteProbeAbility();
			$probe->throw_in_execute = true;
			return $this->write( $probe, array(), new SpyLogger() );
		};

		$exits[] = function () {
			$probe             = new WriteProbeAbility();
			$probe->on_execute = static fn(): \WP_Error => new \WP_Error( 'probe_failed', 'x' );
			return $this->write( $probe, array(), new SpyLogger() );
		};

		$exits[] = fn() => $this->write( new WriteProbeAbility(), array(), new SpyLogger( true, true, false ) );

		foreach ( $exits as $exit ) {
			$exit();
			$this->assertFalse( Pipeline::is_writing() );
		}
	}

	/**
	 * A shutdown hook that runs during a write must not see the write as in progress. Runs in its own process, because
	 * do_action( 'shutdown' ) makes did_action( 'shutdown' ) non-zero for the rest of the process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_writing_is_false_inside_shutdown_hooks_that_run_during_a_write(): void {
		remove_all_actions( 'shutdown' );
		wp_set_current_user( $this->make_agent() );
		$during = null;
		$inside = null;
		$hook   = static function () use ( &$during ): void {
			$during = Pipeline::is_writing();
		};
		add_action( 'shutdown', $hook );

		$probe             = new WriteProbeAbility();
		$probe->on_execute = static function () use ( &$inside ): array {
			$inside = Pipeline::is_writing();
			do_action( 'shutdown' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's shutdown hook, fired on purpose.

			return array();
		};

		try {
			$this->write( $probe, array(), new SpyLogger() );
		} finally {
			remove_action( 'shutdown', $hook );
		}

		$this->assertTrue( $inside, 'Control: the write is in progress inside execute().' );
		$this->assertFalse( $during, 'Shutdown hooks must not see a write in progress.' );
	}

	public function test_a_read_ability_takes_the_old_path_with_no_reservation_and_no_guardrails(): void {
		wp_set_current_user( $this->make_agent() );
		$spy    = new SpyLogger();
		$engine = new Engine(
			array( new FakeRule( 'steadhand/would-block', static fn(): Result => Result::block( 'No.' ), $spy ) ),
			array( $this->allowing( $spy, 'steadhand/last' ) )
		);

		$result = ( new Pipeline( $spy, $engine ) )->run( new GetSiteInfo(), array() );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $spy->events, 'A successful read is not logged, does not reserve a row and runs no rule.' );
	}

	public function test_the_row_names_the_object_from_logged_object_after(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe               = new WriteProbeAbility();
		$probe->object       = array(
			'type' => 'post',
			'id'   => 0,
		);
		$probe->on_execute   = static fn(): array => array( 'id' => 777 );
		$probe->logged_after = array(
			'type' => 'post',
			'id'   => 777,
		);

		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( 777, (int) $row['object_id'] );
	}

	public function test_a_throwing_logged_object_after_keeps_the_object_named_before_execute(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                        = new WriteProbeAbility();
		$probe->object                = array(
			'type' => 'post',
			'id'   => 5,
		);
		$probe->throw_in_logged_after = true;

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertIsArray( $result, 'The write still succeeds.' );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 5, (int) $row['object_id'] );
	}

	public function test_a_malformed_object_from_logged_object_after_is_coerced_to_the_empty_object(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe               = new WriteProbeAbility();
		$probe->logged_after = array(
			'type' => 'post',
			'id'   => -3,
		);

		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['object_id'] );
	}

	public function test_a_throwing_guarded_content_blocks_the_write_and_stores_a_blocked_row(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                   = new WriteProbeAbility();
		$probe->throw_in_guarded = true;

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( 'A guardrail could not check this call, so it was refused.', $result->get_error_message() );
		$this->assertFalse( $probe->executed, 'Content that cannot be read is never written.' );
		$this->assertSame( Entry::RESULT_BLOCKED, $this->latest_row( $agent )['result'] );
	}

	public function test_non_text_guarded_content_blocks_the_write(): void {
		wp_set_current_user( $this->make_agent() );
		$probe          = new WriteProbeAbility();
		$probe->guarded = array( 'post_content' => 5 );

		$result = $this->write( $probe, array( 'title' => 'x' ), new SpyLogger() );

		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertFalse( $probe->executed );
	}

	public function test_the_row_records_the_snapshot_id_and_undoable_for_a_successful_write(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                  = new WriteProbeAbility();
		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);
		// A write is undoable only when the state after it was saved too, so the probe reports one.
		$probe->after_result = 56;

		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 55, (int) $row['snapshot_id'] );
		$this->assertSame( 56, (int) $row['after_snapshot_id'] );
		$this->assertSame( 1, (int) $row['undoable'] );
	}

	public function test_a_failed_write_keeps_its_snapshot_id_but_is_never_undoable(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                   = new WriteProbeAbility();
		$probe->snapshot_result  = array(
			'id'       => 55,
			'undoable' => true,
		);
		$probe->throw_in_execute = true;

		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( 55, (int) $row['snapshot_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	public function test_a_malformed_snapshot_is_coerced_to_nothing_saved(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                  = new WriteProbeAbility();
		$probe->snapshot_result = array(
			'id'       => -1,
			'undoable' => true,
		);

		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['snapshot_id'] );
		$this->assertSame( 0, (int) $row['undoable'], 'A snapshot with no ID cannot be undone.' );
	}

	public function test_a_write_the_caller_may_not_make_is_refused_before_any_guardrail_runs(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::WRITE_DRAFTS => false ) ) );
		$spy    = new SpyLogger();
		$engine = new Engine( array( $this->allowing( $spy ) ), array() );
		$probe  = new WriteProbeAbility( Capabilities::WRITE_DRAFTS, $spy );

		$result = $this->write( $probe, array( 'title' => 'x' ), $spy, $engine );

		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertFalse( $probe->executed );
		$this->assertSame( array( 'log' ), $spy->events, 'The refusal is the only thing logged; no rule and no reservation.' );
	}

	public function test_the_rate_limit_refuses_the_thirty_first_write_through_the_pipeline(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$this->rows_for( $agent, 30 );
		$engine = new Engine(
			array( new RateLimit( new DbLogger(), static fn(): array => array( 'steadhand/probe-write' ) ) ),
			array()
		);
		$probe  = new WriteProbeAbility();

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger(), $engine );

		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( 'This agent has made too many changes in the last minute. Wait a minute, then try again.', $result->get_error_message() );
		$this->assertFalse( $probe->executed );
	}

	public function test_the_edit_lock_refuses_a_write_to_a_post_another_user_is_editing(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$human = self::factory()->user->create();
		$post  = self::factory()->post->create();
		update_post_meta( $post, '_edit_lock', time() . ':' . $human );
		$engine        = new Engine( array( new EditLock() ), array() );
		$probe         = new WriteProbeAbility();
		$probe->object = array(
			'type' => 'post',
			'id'   => $post,
		);

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger(), $engine );

		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertFalse( $probe->executed );
	}

	public function test_content_limits_refuse_oversized_content_in_a_write(): void {
		wp_set_current_user( $this->make_agent() );
		$engine         = new Engine( array(), array( new ContentLimits() ) );
		$probe          = new WriteProbeAbility();
		$probe->guarded = array( 'post_content' => str_repeat( 'a', 262145 ) );

		$result = $this->write( $probe, array( 'title' => 'x' ), new SpyLogger(), $engine );

		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertFalse( $probe->executed );
	}

	public function test_block_markup_refuses_an_unclosed_block_in_a_write(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$engine         = new Engine( array(), array( new BlockMarkup() ) );
		$probe          = new WriteProbeAbility();
		$probe->guarded = array( 'post_content' => '<!-- wp:group --><p>x</p>' );

		$result = $this->write( $probe, array( 'title' => 'x' ), new DbLogger(), $engine );

		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertStringContainsString( 'never closed', $result->get_error_message() );
		$this->assertFalse( $probe->executed );
	}

	/**
	 * Stores write rows for the agent, as if it had made that many writes in the last minute.
	 *
	 * @param int $user_id User ID.
	 * @param int $count   Number of rows.
	 */
	private function rows_for( int $user_id, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->insert_row(
				array(
					'user_id' => $user_id,
					'ability' => 'steadhand/probe-write',
					'result'  => Entry::RESULT_OK,
				)
			);
		}
	}


	/**
	 * A write that is undoable before the change has an after-state snapshot, and the row names it.
	 */
	public function test_a_write_records_the_after_snapshot_and_is_undoable_when_both_snapshots_exist(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);

		$probe->after_result = 56;

		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 55, (int) $row['snapshot_id'] );
		$this->assertSame( 56, (int) $row['after_snapshot_id'] );
		$this->assertSame( 1, (int) $row['undoable'] );
	}

	/**
	 * A call that was undoable before the change, but whose after-state could not be saved, is not undoable, and the
	 * caller is told so in the warnings.
	 */
	public function test_an_undoable_call_without_an_after_state_adds_the_cannot_be_undone_warning(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);
		$probe->after_result    = 0;
		$probe->on_execute      = static fn(): array => array(
			'changed'  => true,
			'warnings' => array( 'An existing warning.' ),
		);

		$out = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertSame(
			array( 'An existing warning.', 'Steadhand could not save the state after this change, so it cannot be undone.' ),
			$out['warnings']
		);
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	/**
	 * When the warnings list is full, the last warning is replaced by the cannot-be-undone warning, not dropped, and the
	 * count stays at the cap.
	 */
	public function test_the_cannot_be_undone_warning_replaces_the_last_entry_of_a_full_list(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);
		$probe->after_result    = 0;
		$full                   = array_map( static fn( int $i ): string => "Warning $i.", range( 1, \Steadhand\Abilities\PostFields::MAX_WARNINGS ) );
		$probe->on_execute      = static fn(): array => array(
			'changed'  => true,
			'warnings' => $full,
		);

		$out = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$expected   = array_slice( $full, 0, \Steadhand\Abilities\PostFields::MAX_WARNINGS - 1 );
		$expected[] = 'Steadhand could not save the state after this change, so it cannot be undone.';
		$this->assertSame( $expected, $out['warnings'] );
		$this->assertCount( \Steadhand\Abilities\PostFields::MAX_WARNINGS, $out['warnings'] );
		$this->assertSame( 0, (int) $this->latest_row( $agent )['undoable'] );
	}

	/**
	 * A call that changed nothing gets no warning, even when its before-snapshot was undoable and its after-state was not
	 * saved.
	 */
	public function test_a_call_that_changed_nothing_gets_no_cannot_be_undone_warning(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);
		$probe->after_result    = 0;
		$probe->on_execute      = static fn(): array => array(
			'changed'  => false,
			'warnings' => array( 'An existing warning.' ),
		);

		$out = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertSame( array( 'An existing warning.' ), $out['warnings'] );
		$this->assertSame( 0, (int) $this->latest_row( $agent )['undoable'] );
	}

	/**
	 * An output with no 'changed' key is treated as a change: the warning is added, so a missing key cannot hide one.
	 */
	public function test_an_undoable_call_whose_output_has_no_changed_key_adds_the_warning(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);
		$probe->after_result    = 0;
		$probe->on_execute      = static fn(): array => array(
			'warnings' => array( 'An existing warning.' ),
		);

		$out = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertSame(
			array( 'An existing warning.', 'Steadhand could not save the state after this change, so it cannot be undone.' ),
			$out['warnings']
		);
		$this->assertSame( 0, (int) $this->latest_row( $agent )['undoable'] );
	}

	/**
	 * An output without a warnings list gets a new list that holds only the cannot-be-undone warning.
	 */
	public function test_an_undoable_call_whose_output_has_no_warnings_list_gets_a_list_with_the_warning(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);
		$probe->after_result    = 0;
		$probe->on_execute      = static fn(): array => array( 'changed' => true );

		$out = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertSame(
			array( 'Steadhand could not save the state after this change, so it cannot be undone.' ),
			$out['warnings']
		);
		$this->assertSame( 0, (int) $this->latest_row( $agent )['undoable'] );
	}

	/**
	 * A call whose after-state was saved, or that was never undoable, gets no such warning.
	 */
	public function test_the_cannot_be_undone_warning_is_only_for_a_call_that_was_undoable_before_the_change(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		$saved                  = new WriteProbeAbility();
		$saved->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);
		$saved->after_result    = 56;
		$saved->on_execute      = static fn(): array => array( 'warnings' => array() );
		$out                    = $this->write( $saved, array( 'title' => 'x' ), new DbLogger() );
		$this->assertSame( array(), $out['warnings'] );

		$never                  = new WriteProbeAbility();
		$never->snapshot_result = array(
			'id'       => 55,
			'undoable' => false,
		);
		$never->after_result    = 0;
		$never->on_execute      = static fn(): array => array( 'warnings' => array() );
		$out                    = $this->write( $never, array( 'title' => 'x' ), new DbLogger() );
		$this->assertSame( array(), $out['warnings'] );
	}

	/**
	 * Runs snapshot_after() only inside the write bracket, and only for an undoable call.
	 */
	public function test_snapshot_after_runs_inside_the_write_only_when_the_snapshot_was_undoable(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);

		$probe->after_result = 56;
		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );
		$this->assertTrue( $probe->writing_in_snapshot_after, 'snapshot_after() must run inside the write.' );

		$not_undoable = new WriteProbeAbility();

		$not_undoable->snapshot_result = array(
			'id'       => 55,
			'undoable' => false,
		);

		$not_undoable->after_result = 56;
		$this->write( $not_undoable, array( 'title' => 'x' ), new DbLogger() );

		$this->assertNull( $not_undoable->writing_in_snapshot_after, 'snapshot_after() must not run when the snapshot was not undoable.' );
		$this->assertSame( 0, (int) $this->latest_row( $agent )['after_snapshot_id'] );
	}

	/**
	 * A snapshot_after() that throws leaves the write done: the output is returned, the row is ok, and the call is not
	 * undoable.
	 */
	public function test_a_throwing_snapshot_after_keeps_the_write_and_stores_no_after_snapshot(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);

		$probe->throw_in_snapshot_after = true;

		$out = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertIsArray( $out, 'The write must still succeed.' );
		$this->assertTrue( $probe->executed );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	/**
	 * A snapshot_after() that returns something other than a positive integer stores 0 and is not undoable. A value of
	 * the wrong type fails the parent's int return type, and that is a throw too.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_after_results(): array {
		return array(
			'a string'   => array( '56' ),
			'a null'     => array( null ),
			'a negative' => array( -1 ),
			'zero'       => array( 0 ),
		);
	}

	/**
	 * A snapshot_after() result that is not a positive integer stores 0 and is not undoable.
	 *
	 * @dataProvider bad_after_results
	 *
	 * @param mixed $bad What snapshot_after() returns.
	 */
	public function test_a_snapshot_after_that_is_not_a_positive_integer_stores_no_after_snapshot( mixed $bad ): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);

		$probe->after_result = $bad;

		$out = $this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertIsArray( $out );
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	/**
	 * An after-snapshot equal to the before-snapshot proves nothing about the change, so it is stored as 0.
	 */
	public function test_a_snapshot_after_equal_to_the_before_snapshot_is_stored_as_zero(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);

		$probe->after_result = 55;

		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$row = $this->latest_row( $agent );
		$this->assertSame( 55, (int) $row['snapshot_id'] );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	/**
	 * A write whose execute() fails never has an after-state, even when its snapshot_after() would return one.
	 */
	public function test_a_failed_write_stores_no_after_snapshot_and_never_asks_for_one(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();

		$probe->snapshot_result = array(
			'id'       => 55,
			'undoable' => true,
		);

		$probe->after_result = 56;

		$probe->throw_in_execute = true;

		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertNull( $probe->writing_in_snapshot_after, 'snapshot_after() must not run after a failed execute().' );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
	}

	public function test_current_log_id_is_zero_outside_a_write(): void {
		$this->assertSame( 0, Pipeline::current_log_id() );
	}

	public function test_current_log_id_is_the_reserved_row_inside_a_write_and_zero_after_it(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new WriteProbeAbility();
		$seen  = null;

		$probe->on_execute = static function ( array $input ) use ( &$seen ): array {
			unset( $input );
			$seen = Pipeline::current_log_id();

			return array();
		};
		$this->write( $probe, array( 'title' => 'x' ), new DbLogger() );

		$this->assertGreaterThan( 0, $seen );
		$this->assertSame( (int) $this->latest_row( $agent )['id'], $seen, 'The ID is the row the write reserved.' );
		$this->assertSame( 0, Pipeline::current_log_id() );
	}

	public function test_current_log_id_is_restored_to_the_outer_write_after_a_nested_write(): void {
		wp_set_current_user( $this->make_agent() );
		$outer    = new WriteProbeAbility();
		$outer_id = null;
		$inner_id = null;
		$after    = null;

		$outer->on_execute = function ( array $input ) use ( &$outer_id, &$inner_id, &$after ): array {
			unset( $input );
			$outer_id = Pipeline::current_log_id();

			$inner             = new WriteProbeAbility();
			$inner->on_execute = static function ( array $input ) use ( &$inner_id ): array {
				unset( $input );
				$inner_id = Pipeline::current_log_id();

				return array();
			};
			( new Pipeline( new DbLogger() ) )->run( $inner, array( 'title' => 'inner' ) );

			$after = Pipeline::current_log_id();

			return array();
		};
		$this->write( $outer, array( 'title' => 'outer' ), new DbLogger() );

		$this->assertGreaterThan( 0, $outer_id );
		$this->assertGreaterThan( 0, $inner_id );
		$this->assertNotSame( $outer_id, $inner_id, 'Each write has its own row.' );
		$this->assertSame( $outer_id, $after, 'The outer write\'s ID is current again after the nested write.' );
	}

	public function test_current_log_id_is_zero_after_a_write_whose_execute_throws_and_the_outer_id_is_back_after_a_nested_throw(): void {
		wp_set_current_user( $this->make_agent() );
		$outer    = new WriteProbeAbility();
		$outer_id = null;
		$after    = null;

		$outer->on_execute = function ( array $input ) use ( &$outer_id, &$after ): array {
			unset( $input );
			$outer_id = Pipeline::current_log_id();

			$inner                   = new WriteProbeAbility();
			$inner->throw_in_execute = true;
			( new Pipeline( new DbLogger() ) )->run( $inner, array( 'title' => 'inner' ) );

			$after = Pipeline::current_log_id();

			return array();
		};
		$this->write( $outer, array( 'title' => 'outer' ), new DbLogger() );
		$this->assertSame( $outer_id, $after, 'A nested write that throws restores the outer ID.' );
		$this->assertSame( 0, Pipeline::current_log_id(), 'The outer write is finished.' );

		$throwing                   = new WriteProbeAbility();
		$throwing->throw_in_execute = true;
		$this->write( $throwing, array( 'title' => 'x' ), new DbLogger() );
		$this->assertSame( 0, Pipeline::current_log_id(), 'A write whose execute throws leaves no ID behind.' );
	}
}
