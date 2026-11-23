<?php
/**
 * The steadhand/get-change-log ability, end to end over MCP: what an agent can see in its own log, and what it cannot.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\GetChangeLog;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §7 (the change log) and §10 for the read tool. Each agent sees only its own entries, and no input
 * widens that.
 */
final class GetChangeLogTest extends ReadToolTestCase {

	use LogRows;

	private const TOOL_NAME = 'steadhand-get-change-log';

	public function test_the_ability_is_registered_with_a_strict_schema_and_is_in_the_server_tool_list(): void {
		$ability = wp_get_ability( 'steadhand/get-change-log' );
		$this->assertNotNull( $ability );
		$this->assertFalse( $ability->get_input_schema()['additionalProperties'] );
		$this->assertFalse( $ability->get_output_schema()['additionalProperties'] );

		wp_set_current_user( $this->make_agent() );
		$names = wp_list_pluck( $this->body( $this->mcp( 'tools/list', array(), $this->mcp_session() ) )['result']['tools'], 'name' );
		$this->assertContains( self::TOOL_NAME, $names );
	}

	public function test_an_agent_sees_its_own_entries_newest_first_with_the_documented_fields(): void {
		$agent = $this->make_agent();
		$this->log( $this->entry( $agent, Entry::RESULT_OK, 'first' ) );
		$this->log( $this->entry( $agent, Entry::RESULT_BLOCKED, 'second' ) );
		$this->log( $this->entry( $agent, Entry::RESULT_ERROR, 'third' ) );
		$this->act_as( $agent );

		$output = $this->output( self::TOOL_NAME );

		$this->assertSame( 3, $output['total'] );
		$this->assertSame( array( 'third', 'second', 'first' ), array_column( $output['items'], 'message' ) );
		$this->assertSame(
			array( 'id', 'created_at', 'ability', 'object_type', 'object_id', 'result', 'message', 'guardrail_notes', 'undoable', 'undone' ),
			array_keys( $output['items'][0] )
		);
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $output['items'][0]['created_at'] );
		$this->assertFalse( $output['items'][0]['undone'] );
	}

	public function test_an_agent_never_sees_another_agents_entries(): void {
		$mine  = $this->make_agent();
		$other = $this->make_agent();
		$this->log( $this->entry( $other, Entry::RESULT_BLOCKED, 'belongs to the other agent' ) );
		$this->log( $this->entry( $mine, Entry::RESULT_OK, 'mine' ) );
		$this->act_as( $mine );

		$output = $this->output( self::TOOL_NAME );

		$this->assertSame( 1, $output['total'] );
		$this->assertSame( array( 'mine' ), array_column( $output['items'], 'message' ) );
		$this->assertStringNotContainsString( 'belongs to the other agent', (string) wp_json_encode( $output ) );
	}

	/**
	 * Arguments that name another user, or ask for everything, are refused by the strict schema. None widens the view.
	 */
	public function test_no_argument_widens_the_view_to_another_agent(): void {
		$mine  = $this->make_agent();
		$other = $this->make_agent();
		$this->log( $this->entry( $other, Entry::RESULT_BLOCKED, 'the other agent\'s refusal' ) );
		$this->act_as( $mine );

		foreach ( array( 'user_id', 'user', 'all', 'agent_id', 'scope' ) as $argument ) {
			$result = $this->tool_result( self::TOOL_NAME, array( $argument => $other ) );
			$this->schema_refusal_text( $result );
			$this->assertStringNotContainsString( 'the other agent', (string) wp_json_encode( $result ), "$argument must not reveal another agent's entries." );
		}
	}

	public function test_an_agent_without_steadhand_read_is_refused(): void {
		$agent = $this->make_agent( array( Capabilities::READ => false ) );
		$this->log( $this->entry( $agent, Entry::RESULT_OK, 'hidden' ) );
		$this->act_as( $agent );

		$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME ) );

		$this->assertStringContainsString( 'steadhand_read', $text );
	}

	public function test_the_output_never_contains_the_stored_input(): void {
		$agent = $this->make_agent();
		$this->log( $this->entry( $agent, Entry::RESULT_OK, 'with input', array( 'title' => 'PLANTED_INPUT_VALUE_12345' ) ) );
		$this->act_as( $agent );

		$result = $this->tool_result( self::TOOL_NAME );

		$this->assertFalse( $result['isError'] );
		$this->assertStringNotContainsString( 'PLANTED_INPUT_VALUE_12345', (string) wp_json_encode( $result ) );
		$this->assertNotContains( 'input', $this->keys_at_any_depth( $result ) );
	}

	public function test_filters_by_result_ability_and_date_work(): void {
		$agent = $this->make_agent();
		$this->insert_row(
			array(
				'user_id'    => $agent,
				'created_at' => '2026-01-10 12:00:00',
				'ability'    => 'steadhand/get-post',
				'result'     => Entry::RESULT_OK,
				'message'    => 'january ok',
			)
		);
		$this->insert_row(
			array(
				'user_id'    => $agent,
				'created_at' => '2026-02-10 12:00:00',
				'ability'    => 'steadhand/list-posts',
				'result'     => Entry::RESULT_BLOCKED,
				'message'    => 'february blocked',
			)
		);
		$this->insert_row(
			array(
				'user_id'    => $agent,
				'created_at' => '2026-03-10 12:00:00',
				'ability'    => 'steadhand/get-post',
				'result'     => Entry::RESULT_ERROR,
				'message'    => 'march error',
			)
		);
		$this->act_as( $agent );

		$this->assertSame( array( 'february blocked' ), $this->messages( array( 'result' => 'blocked' ) ) );
		$this->assertSame( array( 'march error', 'january ok' ), $this->messages( array( 'ability' => 'steadhand/get-post' ) ) );
		$this->assertSame( array( 'march error', 'february blocked' ), $this->messages( array( 'after' => '2026-02-01T00:00:00Z' ) ) );
		$this->assertSame( array( 'february blocked', 'january ok' ), $this->messages( array( 'before' => '2026-02-28T00:00:00Z' ) ) );
		$this->assertSame(
			array( 'february blocked' ),
			$this->messages(
				array(
					'after'  => '2026-02-01T00:00:00Z',
					'before' => '2026-02-28T00:00:00Z',
				)
			)
		);
	}

	public function test_paging_reports_the_right_totals(): void {
		$agent = $this->make_agent();
		for ( $i = 0; $i < 25; $i++ ) {
			$this->log( $this->entry( $agent, Entry::RESULT_OK, "entry $i" ) );
		}
		$this->act_as( $agent );

		$first = $this->output(
			self::TOOL_NAME,
			array(
				'per_page' => 10,
				'page'     => 1,
			)
		);
		$last  = $this->output(
			self::TOOL_NAME,
			array(
				'per_page' => 10,
				'page'     => 3,
			)
		);
		$past  = $this->output(
			self::TOOL_NAME,
			array(
				'per_page' => 10,
				'page'     => 4,
			)
		);

		$this->assertCount( 10, $first['items'] );
		$this->assertSame( 25, $first['total'] );
		$this->assertSame( 3, $first['total_pages'] );
		$this->assertSame( 1, $first['page'] );
		$this->assertSame( 10, $first['per_page'] );
		$this->assertCount( 5, $last['items'] );
		$this->assertSame( 25, $last['total'] );
		$this->assertSame( array(), $past['items'], 'A page past the end is empty, not an error.' );
		$this->assertSame( 25, $past['total'] );
	}

	public function test_per_page_above_fifty_and_page_zero_are_refused(): void {
		$this->act_as( $this->make_agent() );

		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'per_page' => 51 ) ) );
		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'page' => 0 ) ) );
	}

	public function test_a_malformed_ability_or_result_filter_is_refused(): void {
		$this->act_as( $this->make_agent() );

		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'ability' => '../../etc' ) ) );
		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'result' => 'all' ) ) );
	}

	/**
	 * Only the SELECT fails: the table exists and its inserts work. The caller gets steadhand_log_unavailable, not an
	 * empty list, and the failed read is itself stored as one error row.
	 */
	public function test_a_read_whose_select_fails_returns_steadhand_log_unavailable_and_stores_one_error_row(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$this->log( $this->entry( $agent, Entry::RESULT_OK, 'the stored row is not read back' ) );
		$filter = $this->make_selects_fail();

		$result = ( new Pipeline( new DbLogger() ) )->run( new GetChangeLog( new DbLogger() ), array() );

		remove_filter( 'query', $filter );
		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( 2, $this->count_rows( $agent ), 'The earlier entry, and the one error row for the failed read.' );
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( $result->get_error_message(), $row['message'] );
	}

	/**
	 * Only the page query fails; the count succeeds. The caller still gets steadhand_log_unavailable, not an empty page.
	 */
	public function test_a_read_whose_page_query_fails_returns_steadhand_log_unavailable(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$this->log( $this->entry( $agent, Entry::RESULT_OK, 'present but not readable' ) );
		$table  = \Steadhand\Log\Schema::table();
		$filter = static function ( string $query ) use ( $table ): string {
			return str_starts_with( $query, 'SELECT id, created_at' )
				? str_replace( 'FROM ' . $table . ' ', 'FROM ' . $table . '_missing ', $query )
				: $query;
		};
		add_filter( 'query', $filter );

		$result = ( new Pipeline( new DbLogger() ) )->run( new GetChangeLog( new DbLogger() ), array() );

		remove_filter( 'query', $filter );
		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_unavailable', $result->get_error_code() );
	}

	/**
	 * The table cannot be read or written: the read fails, and the error it returns cannot be recorded either. The caller
	 * gets steadhand_log_failed, because the failure cannot be logged.
	 */
	public function test_a_table_unusable_for_reads_and_writes_returns_steadhand_log_failed(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$filter = $this->make_selects_fail();
		$this->make_inserts_fail();

		$result = ( new Pipeline( new DbLogger() ) )->run( new GetChangeLog( new DbLogger() ), array() );

		remove_filter( 'query', $filter );
		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
	}

	/**
	 * The existence check fails (the table looks missing) and the inserts fail too. The read is refused before any query,
	 * and the error cannot be recorded, so the caller gets steadhand_log_failed.
	 */
	public function test_a_missing_table_whose_error_cannot_be_recorded_returns_steadhand_log_failed(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$this->make_table_look_missing();
		$this->make_inserts_fail();

		$result = ( new Pipeline( new DbLogger() ) )->run( new GetChangeLog( new DbLogger() ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
	}

	/**
	 * A filter that breaks every SELECT on the change-log table. Returns the filter, so the caller can remove it before
	 * it reads the table itself.
	 *
	 * @return \Closure
	 */
	private function make_selects_fail(): \Closure {
		$table  = \Steadhand\Log\Schema::table();
		$filter = static function ( string $query ) use ( $table ): string {
			return str_starts_with( $query, 'SELECT ' ) && str_contains( $query, 'FROM ' . $table . ' ' )
				? str_replace( 'FROM ' . $table . ' ', 'FROM ' . $table . '_missing ', $query )
				: $query;
		};
		add_filter( 'query', $filter );

		return $filter;
	}

	/**
	 * Calls the tool with the filters and returns the messages of the entries it found, in order.
	 *
	 * @param array<string, string> $arguments Tool arguments.
	 * @return string[]
	 */
	private function messages( array $arguments ): array {
		return array_column( $this->output( self::TOOL_NAME, $arguments )['items'], 'message' );
	}

	/**
	 * Makes a user the current user.
	 *
	 * @param int $user_id User ID.
	 */
	private function act_as( int $user_id ): void {
		wp_set_current_user( $user_id );
	}
}
