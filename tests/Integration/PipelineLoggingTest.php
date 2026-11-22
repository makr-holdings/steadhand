<?php
/**
 * What the pipeline writes to the change log, with the real DbLogger: successful reads are not logged, refusals are.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Abilities\GetPost;
use Steadhand\Abilities\GetSiteInfo;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §7 ("logging must not fail open", refused calls logged) for the pipeline's own rules.
 */
final class PipelineLoggingTest extends LogTestCase {

	public function test_a_successful_read_call_stores_no_row(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		$result = $this->pipeline()->run( new GetSiteInfo(), array() );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $this->count_rows( $agent ) );
	}

	public function test_a_successful_read_is_stored_as_ok_when_the_filter_says_so(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		add_filter( 'steadhand_log_read_calls', '__return_true' );

		$this->pipeline()->run( new GetSiteInfo(), array() );

		$this->assertSame( 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 'steadhand/get-site-info', $row['ability'] );
	}

	/**
	 * Only a filter that returns exactly true turns read logging on. 1 and 'yes' are truthy but not true.
	 */
	public function test_the_read_filter_must_return_exactly_true(): void {
		foreach ( array( 1, 'yes' ) as $value ) {
			$agent = $this->make_agent();
			wp_set_current_user( $agent );
			$filter = static fn(): mixed => $value;
			add_filter( 'steadhand_log_read_calls', $filter );

			$this->pipeline()->run( new GetSiteInfo(), array() );

			$this->assertSame( 0, $this->count_rows( $agent ), 'A filter returning ' . (string) wp_json_encode( $value ) . ' must not log.' );
			remove_filter( 'steadhand_log_read_calls', $filter );
		}
	}

	public function test_a_throwing_read_filter_counts_as_false(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		add_filter(
			'steadhand_log_read_calls',
			static function (): bool {
				throw new \RuntimeException( 'Filter failed.' );
			}
		);

		$result = $this->pipeline()->run( new GetSiteInfo(), array() );

		$this->assertIsArray( $result, 'The call still succeeds.' );
		$this->assertSame( 0, $this->count_rows( $agent ) );
	}

	public function test_a_refused_read_call_stores_one_blocked_row(): void {
		$agent = $this->make_agent( array( Capabilities::READ => false ) );
		wp_set_current_user( $agent );

		$result = $this->pipeline()->run( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_BLOCKED, $row['result'] );
		$this->assertSame( 'steadhand/get-site-info', $row['ability'] );
		$this->assertSame( $agent, (int) $row['user_id'] );
	}

	public function test_a_refused_get_post_for_a_missing_id_records_the_post_as_the_object(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		$result = $this->pipeline()->run( new GetPost(), array( 'id' => 999999999 ) );

		$this->assertWPError( $result );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_BLOCKED, $row['result'] );
		$this->assertSame( 'steadhand/get-post', $row['ability'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( 999999999, (int) $row['object_id'] );
	}

	public function test_a_successful_get_post_records_the_post_as_the_object(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		add_filter( 'steadhand_log_read_calls', '__return_true' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->pipeline()->run( new GetPost(), array( 'id' => $post ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( $post, (int) $row['object_id'] );
	}

	public function test_invalid_input_stores_one_blocked_row_with_redacted_input(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		$result = $this->pipeline()->run(
			new GetSiteInfo(),
			array(
				'extra'    => 'x',
				'password' => 'Hunter2secret',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_BLOCKED, $row['result'] );
		$this->assertStringNotContainsString( 'Hunter2secret', $row['input'] );
		$this->assertSame( '[redacted]', json_decode( $row['input'], true )['password'] );
	}

	public function test_a_refused_run_returns_steadhand_log_failed_when_the_insert_fails(): void {
		$agent = $this->make_agent( array( Capabilities::READ => false ) );
		wp_set_current_user( $agent );
		$this->make_inserts_fail();

		ob_start();
		$result = $this->pipeline()->run( new GetSiteInfo(), array() );
		$output = (string) ob_get_clean();

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
		$this->assertSame( '', $output );
	}

	public function test_a_successful_read_still_returns_when_the_insert_would_fail(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$this->make_inserts_fail();

		$result = $this->pipeline()->run( new GetSiteInfo(), array() );

		$this->assertIsArray( $result, 'A read that is not logged does not depend on the log write.' );
	}

	public function test_a_logged_read_is_withheld_when_the_insert_fails(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		add_filter( 'steadhand_log_read_calls', '__return_true' );
		$this->make_inserts_fail();

		$result = $this->pipeline()->run( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
	}

	public function test_a_successful_write_style_call_is_stored_as_ok(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$write = new class() extends Ability {
			public function name(): string {
				return 'steadhand/probe-write';
			}

			public function label(): string {
				return 'Probe write';
			}

			public function description(): string {
				return 'Test probe.';
			}

			public function input_schema(): array {
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(),
				);
			}

			public function output_schema(): array {
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(),
				);
			}

			public function annotations(): array {
				return array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				);
			}

			public function capability(): string {
				return Capabilities::READ;
			}

			public function authorize_object( array $input ): bool {
				unset( $input );
				return true;
			}

			public function execute( array $input ): array {
				unset( $input );
				return array();
			}
		};

		$this->assertIsArray( $this->pipeline()->run( $write, array() ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 'steadhand/probe-write', $row['ability'] );
	}

	/**
	 * A write-style probe: not read-only, and it needs steadhand_write_drafts, which the agent role holds.
	 *
	 * @param \Closure|null $on_execute Runs inside execute(); may return a WP_Error.
	 */
	private function write_probe( ?\Closure $on_execute = null ): ProbeAbility {
		return new ProbeAbility(
			Capabilities::WRITE_DRAFTS,
			$on_execute,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * A write whose change-log entry cannot be stored must not be returned, with its output. A failing database insert.
	 */
	public function test_a_write_is_withheld_when_its_log_insert_fails_and_it_returned_output(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = $this->write_probe( static fn(): array => array( 'done' => true ) );
		$this->make_inserts_fail();

		$result = $this->pipeline()->run( $probe, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
		$this->assertFalse( $probe->executed, 'A write whose change cannot be reserved in the log must not run.' );
	}

	/**
	 * The same, when the ability fails: the caller gets steadhand_log_failed, not the ability's own error.
	 */
	public function test_a_write_is_withheld_when_its_log_insert_fails_and_it_returned_an_error(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = $this->write_probe( static fn(): \WP_Error => new \WP_Error( 'probe_failed', 'Probe failed.', array( 'status' => 500 ) ) );
		$this->make_inserts_fail();

		$result = $this->pipeline()->run( $probe, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
	}

	public function test_a_write_is_withheld_when_the_logger_reports_failure_and_it_returned_output(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = $this->write_probe( static fn(): array => array( 'done' => true ) );

		$result = ( new Pipeline( new SpyLogger( false, false ) ) )->run( $probe, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
	}

	public function test_a_write_is_withheld_when_the_logger_reports_failure_and_it_returned_an_error(): void {
		wp_set_current_user( $this->make_agent() );
		$probe = $this->write_probe( static fn(): \WP_Error => new \WP_Error( 'probe_failed', 'Probe failed.', array( 'status' => 500 ) ) );

		$result = ( new Pipeline( new SpyLogger( false, false ) ) )->run( $probe, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
	}

	/**
	 * After 61 refusals the throttle drops refusals, but an error is written after execute() ran and is always stored.
	 */
	public function test_an_error_is_stored_after_the_refusal_throttle_has_started_dropping(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		// The agent role does not hold steadhand_publish, so every call to this probe is refused.
		$refused  = new ProbeAbility( Capabilities::PUBLISH );
		$pipeline = $this->pipeline();

		for ( $i = 0; $i < DbLogger::REFUSALS_PER_MINUTE + 1; $i++ ) {
			$pipeline->run( $refused, array() );
		}
		$this->assertSame( DbLogger::REFUSALS_PER_MINUTE + 1, $this->count_rows( $agent ), 'Sixty refusals and one marker.' );

		$write  = $this->write_probe( static fn(): \WP_Error => new \WP_Error( 'probe_failed', 'Probe failed.', array( 'status' => 500 ) ) );
		$result = $pipeline->run( $write, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'probe_failed', $result->get_error_code(), 'The caller gets the ability\'s own error.' );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( 'Probe failed.', $row['message'] );
		$this->assertSame( DbLogger::REFUSALS_PER_MINUTE + 2, $this->count_rows( $agent ) );
	}

	/**
	 * A successful call to an ability that has no readonly annotation is logged. Only a read-only ability is skipped.
	 */
	public function test_a_successful_call_to_an_ability_with_no_readonly_annotation_is_logged(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new ProbeAbility(
			Capabilities::READ,
			null,
			array(
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->pipeline()->run( $probe, array() );

		$this->assertSame( 1, $this->count_rows( $agent ) );
		$this->assertSame( Entry::RESULT_OK, $this->latest_row( $agent )['result'] );
	}

	/**
	 * A successful call that is marked readonly but needs a write capability is logged: readonly alone does not skip it.
	 */
	public function test_a_successful_call_marked_readonly_that_needs_a_write_capability_is_logged(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe = new ProbeAbility( Capabilities::WRITE_DRAFTS );

		$this->pipeline()->run( $probe, array() );

		$this->assertSame( 1, $this->count_rows( $agent ) );
		$this->assertSame( Entry::RESULT_OK, $this->latest_row( $agent )['result'] );
	}

	/**
	 * A refused call is logged as blocked even when the ability's log_object() throws: the entry names no object.
	 */
	public function test_a_refused_call_is_logged_when_log_object_throws(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                      = new ProbeAbility( Capabilities::PUBLISH );
		$probe->throw_in_log_object = true;

		$result = $this->pipeline()->run( $probe, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_BLOCKED, $row['result'] );
		$this->assertSame( '', $row['object_type'] );
		$this->assertSame( 0, (int) $row['object_id'] );
	}

	public function test_a_refused_permission_check_is_logged_when_log_object_throws(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                      = new ProbeAbility( Capabilities::PUBLISH );
		$probe->throw_in_log_object = true;

		$result = $this->pipeline()->check( $probe, array() );

		$this->assertWPError( $result );
		$this->assertSame( 1, $this->count_rows( $agent ) );
		$this->assertSame( Entry::RESULT_BLOCKED, $this->latest_row( $agent )['result'] );
		$this->assertSame( '', $this->latest_row( $agent )['object_type'] );
	}

	/**
	 * A malformed object is coerced: a non-string type becomes '' and a negative ID becomes 0.
	 */
	public function test_the_object_is_coerced_when_log_object_returns_malformed_values(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                  = new ProbeAbility( Capabilities::PUBLISH );
		$probe->object_override = array(
			'type' => 42,
			'id'   => -3,
		);

		$this->pipeline()->run( $probe, array() );

		$row = $this->latest_row( $agent );
		$this->assertSame( '', $row['object_type'] );
		$this->assertSame( 0, (int) $row['object_id'] );
	}

	public function test_a_well_formed_object_is_kept(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$probe                  = new ProbeAbility( Capabilities::PUBLISH );
		$probe->object_override = array(
			'type' => 'post',
			'id'   => 7,
		);

		$this->pipeline()->run( $probe, array() );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( 7, (int) $row['object_id'] );
	}

	/**
	 * Known gap (SPEC §7, "Status in M2"): input that fails the tool's schema is refused by the MCP Adapter or core
	 * before Steadhand's permission callback runs. So a schema refusal over MCP writes no change-log row.
	 *
	 * This test documents current behaviour. It must be updated if the gap is closed: a schema refusal should then store
	 * one blocked row. The direct run() path is covered by PipelineTest, which is not this gap.
	 */
	public function test_known_gap_schema_refusals_over_mcp_are_not_logged(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		$refused = array(
			array(
				'id'    => 1,
				'extra' => 'x',
			),
			array(
				'id' => 'abc',
			),
		);
		foreach ( $refused as $arguments ) {
			$result = $this->call_tool( 'steadhand-get-post', $arguments )['result'];

			$this->assertTrue( $result['isError'], 'The schema refusal is returned to the caller.' );
			$this->assertArrayNotHasKey( 'structuredContent', $result );
		}

		$this->assertSame( 0, $this->count_rows( $agent ), 'Known gap: no change-log row is written for a schema refusal over MCP.' );
	}

	/**
	 * A pipeline that writes to the real change log.
	 */
	private function pipeline(): Pipeline {
		return new Pipeline( new DbLogger() );
	}
}
