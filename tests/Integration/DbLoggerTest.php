<?php
/**
 * The change-log writer: what is stored, how it is redacted and capped, throttled, pruned and reported.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Redactor;

/**
 * Covers SPEC §7 and §10 for the change log's storage: redaction, size limits, the refusal throttle, retention, and
 * the failure modes that must never print SQL or fail the call.
 */
final class DbLoggerTest extends LogTestCase {

	private const MARKER = 'Further refused calls from this user are not being logged for up to a minute.';

	/** A GitHub token: ghp_ and 36 alphanumerics. */
	private const GITHUB_TOKEN = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';

	public function test_stores_a_row_with_every_column_correct(): void {
		$user_id = self::factory()->user->create();
		$entry   = new Entry(
			$user_id,
			'Claude Desktop – Rick',
			'steadhand/get-post',
			Entry::RESULT_OK,
			'',
			array( 'id' => 5 ),
			'post',
			5,
			'Kept as requested.',
			7,
			true
		);

		$this->assertTrue( $this->log( $entry ) );

		$row = $this->latest_row( $user_id );
		$this->assertSame( $user_id, (int) $row['user_id'] );
		$this->assertSame( 'Claude Desktop – Rick', $row['agent_label'] );
		$this->assertSame( 'steadhand/get-post', $row['ability'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( 5, (int) $row['object_id'] );
		$this->assertSame( array( 'id' => 5 ), json_decode( $row['input'], true ) );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( '', $row['message'] );
		$this->assertSame( 'Kept as requested.', $row['guardrail_notes'] );
		$this->assertSame( 7, (int) $row['snapshot_id'] );
		$this->assertSame( 1, (int) $row['undoable'] );
		$this->assertSame( 0, (int) $row['undone_by'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at'] );
		$this->assertLessThan( 120, abs( time() - strtotime( $row['created_at'] . ' UTC' ) ), 'created_at is the UTC write time.' );
	}

	public function test_an_empty_input_is_stored_as_an_object(): void {
		$user_id = self::factory()->user->create();

		$this->log( $this->entry( $user_id ) );

		$this->assertSame( '{}', $this->latest_row( $user_id )['input'] );
	}

	public function test_a_sensitive_key_in_the_input_is_masked_in_the_stored_json(): void {
		$user_id = self::factory()->user->create();

		$this->log(
			$this->entry(
				$user_id,
				Entry::RESULT_OK,
				'',
				array(
					'title'    => 'Visible title',
					'password' => 'Hunter2secret',
					'api_key'  => 'abc123def456ghi789',
				)
			)
		);

		$stored = $this->latest_row( $user_id )['input'];
		$this->assertStringNotContainsString( 'Hunter2secret', $stored );
		$this->assertStringNotContainsString( 'abc123def456ghi789', $stored );

		$decoded = json_decode( $stored, true );
		$this->assertSame( 'Visible title', $decoded['title'] );
		$this->assertSame( Redactor::MASK, $decoded['password'] );
		$this->assertSame( Redactor::MASK, $decoded['api_key'] );
	}

	public function test_a_secret_inside_the_message_is_masked(): void {
		$user_id = self::factory()->user->create();

		$this->log( $this->entry( $user_id, Entry::RESULT_ERROR, 'Upstream said Authorization: Bearer abcdefghijklmnop0123456789' ) );

		$message = $this->latest_row( $user_id )['message'];
		$this->assertStringNotContainsString( 'abcdefghijklmnop0123456789', $message );
		$this->assertStringContainsString( Redactor::MASK, $message );
	}

	public function test_oversized_input_is_replaced_by_a_summary_that_is_valid_json(): void {
		$user_id = self::factory()->user->create();

		$this->log(
			$this->entry(
				$user_id,
				Entry::RESULT_OK,
				'',
				array(
					'body'  => str_repeat( 'a', 20000 ),
					'title' => 'x',
				)
			)
		);

		$stored  = $this->latest_row( $user_id )['input'];
		$decoded = json_decode( $stored, true );
		$this->assertIsArray( $decoded, 'The stored input must be valid JSON.' );
		$this->assertTrue( $decoded['truncated'] );
		$this->assertGreaterThan( DbLogger::MAX_INPUT_BYTES, $decoded['bytes'] );
		$this->assertSame( array( 'body', 'title' ), $decoded['keys'] );
		$this->assertStringNotContainsString( str_repeat( 'a', 100 ), $stored, 'The value itself must not be stored.' );
	}

	public function test_the_summary_keeps_at_most_fifty_keys_each_cut_to_a_hundred_bytes(): void {
		$user_id = self::factory()->user->create();
		$input   = array();
		// Letters only, so that no key is masked as an opaque token. The lengths run from 90 to 149 bytes.
		for ( $i = 0; $i < 60; $i++ ) {
			$input[ str_repeat( 'k', 90 + $i ) ] = str_repeat( 'v', 400 );
		}

		$this->log( $this->entry( $user_id, Entry::RESULT_OK, '', $input ) );

		$decoded = json_decode( $this->latest_row( $user_id )['input'], true );
		$this->assertCount( 50, $decoded['keys'] );
		foreach ( $decoded['keys'] as $key ) {
			$this->assertLessThanOrEqual( 100, strlen( $key ) );
		}
	}

	public function test_a_message_is_cut_on_a_utf8_boundary_and_stays_valid(): void {
		$user_id = self::factory()->user->create();
		// Each euro sign is three bytes, and 2000 is not a multiple of three.
		$this->log( $this->entry( $user_id, Entry::RESULT_BLOCKED, str_repeat( "\xe2\x82\xac", 1000 ) ) );

		$message = $this->latest_row( $user_id )['message'];
		$this->assertLessThanOrEqual( DbLogger::MAX_MESSAGE_BYTES, strlen( $message ) );
		$this->assertSame( 1998, strlen( $message ), 'The cut stops before the first incomplete character.' );
		$this->assertTrue( mb_check_encoding( $message, 'UTF-8' ) );
	}

	public function test_an_unknown_result_or_a_malformed_ability_is_refused_and_stores_nothing(): void {
		$user_id = self::factory()->user->create();

		$this->assertFalse( $this->log( new Entry( $user_id, '', 'steadhand/get-post', 'weird' ) ) );
		$this->assertFalse( $this->log( new Entry( $user_id, '', 'get-post', Entry::RESULT_OK ) ) );
		$this->assertFalse( $this->log( new Entry( $user_id, '', 'steadhand/Get-Post', Entry::RESULT_OK ) ) );
		$this->assertFalse( $this->log( new Entry( $user_id, '', 'steadhand/' . str_repeat( 'a', 81 ), Entry::RESULT_OK ) ) );
		$this->assertFalse( $this->log( new Entry( $user_id, '', "steadhand/get-post\n", Entry::RESULT_OK ) ) );
		$this->assertSame( 0, $this->count_rows( $user_id ) );

		$this->assertTrue( $this->log( new Entry( $user_id, '', 'steadhand/get-post', Entry::RESULT_OK ) ), 'A valid entry is still stored.' );
	}

	public function test_sixty_refusals_are_stored_then_one_marker_then_nothing(): void {
		$user_id = self::factory()->user->create();

		for ( $i = 0; $i < DbLogger::REFUSALS_PER_MINUTE; $i++ ) {
			$this->assertTrue( $this->log( $this->entry( $user_id, Entry::RESULT_BLOCKED, "refusal $i" ) ) );
		}
		$this->assertSame( DbLogger::REFUSALS_PER_MINUTE, $this->count_rows( $user_id ) );

		// The 61st refusal has a distinctive input, so that the marker's input can be checked against it.
		$this->assertTrue( $this->log( $this->entry( $user_id, Entry::RESULT_BLOCKED, 'the 61st', array( 'title' => 'DISTINCT_INPUT_61' ) ) ), 'The 61st refusal is handled.' );
		$this->assertSame( DbLogger::REFUSALS_PER_MINUTE + 1, $this->count_rows( $user_id ) );
		$marker = $this->latest_row( $user_id );
		$this->assertSame( self::MARKER, $marker['message'] );
		$this->assertSame( Entry::RESULT_BLOCKED, $marker['result'] );
		$this->assertSame( '{}', $marker['input'], 'The marker carries no input of the entry that triggered it.' );
		$this->assertStringNotContainsString( 'DISTINCT_INPUT_61', $marker['input'] );
		$this->assertSame( 'steadhand/get-site-info', $marker['ability'] );
		$this->assertSame( 'Test agent', $marker['agent_label'] );

		$this->assertTrue( $this->log( $this->entry( $user_id, Entry::RESULT_BLOCKED, 'the 62nd' ) ), 'The 62nd refusal is handled.' );
		$this->assertSame( DbLogger::REFUSALS_PER_MINUTE + 1, $this->count_rows( $user_id ), 'The 62nd refusal stores nothing.' );
	}

	/**
	 * Only refusals are throttled. An error is written after execute() ran, so it is neither counted towards the limit
	 * nor dropped, however many there are.
	 */
	public function test_errors_are_neither_counted_nor_dropped_by_the_refusal_throttle(): void {
		$user_id = self::factory()->user->create();

		for ( $i = 0; $i < 30; $i++ ) {
			$this->log( $this->entry( $user_id, Entry::RESULT_BLOCKED ) );
			$this->log( $this->entry( $user_id, Entry::RESULT_ERROR ) );
		}
		$this->assertSame( 60, $this->count_rows( $user_id ), '30 refusals and 30 errors are all stored: 30 refusals do not reach the limit.' );

		$this->assertTrue( $this->log( $this->entry( $user_id, Entry::RESULT_ERROR, 'the 61st' ) ) );
		$this->assertSame( 'the 61st', $this->latest_row( $user_id )['message'] );
		$this->assertSame( Entry::RESULT_ERROR, $this->latest_row( $user_id )['result'] );

		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertTrue( $this->log( $this->entry( $user_id, Entry::RESULT_ERROR, "error $i" ) ) );
		}
		$this->assertSame( 91, $this->count_rows( $user_id ), 'Every error is stored.' );
		$this->assertSame( 0, $this->count_messages( $user_id, self::MARKER ), 'No marker is written for errors.' );
	}

	/**
	 * The throttle is scoped to one user. The unaffected user is created first, so a scope of user_id >= the user's ID
	 * would count the throttled user's refusals against it.
	 */
	public function test_the_throttle_does_not_drop_an_older_users_refusal_when_a_newer_user_is_throttled(): void {
		$unaffected = self::factory()->user->create();
		$throttled  = self::factory()->user->create();
		$this->refuse( $throttled, DbLogger::REFUSALS_PER_MINUTE + 1 );

		$this->assertTrue( $this->log( $this->entry( $unaffected, Entry::RESULT_BLOCKED, 'unaffected' ) ) );

		$this->assertSame( 1, $this->count_rows( $unaffected ), 'The unaffected user\'s refusal is stored.' );
		$this->assertSame( 'unaffected', $this->latest_row( $unaffected )['message'] );
		$this->assertSame( DbLogger::REFUSALS_PER_MINUTE + 1, $this->count_rows( $throttled ), 'The throttled user keeps its 60 and its marker.' );
	}

	/**
	 * The reverse order: the throttled user is created first, the unaffected user second.
	 */
	public function test_the_throttle_does_not_drop_a_newer_users_refusal_when_an_older_user_is_throttled(): void {
		$throttled  = self::factory()->user->create();
		$unaffected = self::factory()->user->create();
		$this->refuse( $throttled, DbLogger::REFUSALS_PER_MINUTE + 1 );

		$this->assertTrue( $this->log( $this->entry( $unaffected, Entry::RESULT_BLOCKED, 'unaffected' ) ) );

		$this->assertSame( 1, $this->count_rows( $unaffected ) );
		$this->assertSame( DbLogger::REFUSALS_PER_MINUTE + 1, $this->count_rows( $throttled ) );
	}

	/**
	 * The guardrail note and the agent label are redacted as well as the message and input. Each holds a bearer header.
	 */
	public function test_secrets_in_the_guardrail_note_and_the_agent_label_are_masked(): void {
		$user_id = self::factory()->user->create();
		$token   = 'Authorization: Bearer abcdefghijklmnop0123456789';

		$this->log(
			new Entry(
				$user_id,
				$token,
				'steadhand/get-site-info',
				Entry::RESULT_BLOCKED,
				'',
				array(),
				'',
				0,
				$token
			)
		);

		$row = $this->latest_row( $user_id );
		$this->assertStringNotContainsString( 'abcdefghijklmnop0123456789', $row['guardrail_notes'] );
		$this->assertStringNotContainsString( 'abcdefghijklmnop0123456789', $row['agent_label'] );
		$this->assertStringContainsString( Redactor::MASK, $row['guardrail_notes'] );
		$this->assertStringContainsString( Redactor::MASK, $row['agent_label'] );
	}

	public function test_a_secret_in_an_ok_entry_guardrail_note_is_masked(): void {
		$user_id = self::factory()->user->create();

		$this->log(
			new Entry(
				$user_id,
				'',
				'steadhand/get-site-info',
				Entry::RESULT_OK,
				'',
				array(),
				'',
				0,
				'Kept: Authorization: Bearer abcdefghijklmnop0123456789'
			)
		);

		$this->assertStringNotContainsString( 'abcdefghijklmnop0123456789', $this->latest_row( $user_id )['guardrail_notes'] );
	}

	/**
	 * A token used as a key, at the top level and nested, does not reach the stored JSON.
	 */
	public function test_a_github_token_used_as_a_key_is_not_stored_at_top_level_or_nested(): void {
		$user_id = self::factory()->user->create();

		$this->log(
			$this->entry(
				$user_id,
				Entry::RESULT_OK,
				'',
				array(
					self::GITHUB_TOKEN => 'top',
					'outer'            => array( self::GITHUB_TOKEN => 'nested' ),
				)
			)
		);

		$stored = $this->latest_row( $user_id )['input'];
		$this->assertStringNotContainsString( 'abcdefghijklmnopqrstuvwxyz0123456789', $stored );
		$decoded = json_decode( $stored, true );
		$this->assertSame( 'top', $decoded[ Redactor::MASK ] );
		$this->assertSame( 'nested', $decoded['outer'][ Redactor::MASK ] );
	}

	/**
	 * A key that is an Authorization header with a bearer token, at the top level and nested, does not reach the JSON.
	 */
	public function test_an_authorization_header_used_as_a_key_is_not_stored_at_top_level_or_nested(): void {
		$user_id = self::factory()->user->create();
		$key     = 'Authorization: Bearer abcdefghijklmnop0123456789';

		$this->log(
			$this->entry(
				$user_id,
				Entry::RESULT_OK,
				'',
				array(
					$key    => 'top',
					'outer' => array( $key => 'nested' ),
				)
			)
		);

		$stored = $this->latest_row( $user_id )['input'];
		$this->assertStringNotContainsString( 'abcdefghijklmnop0123456789', $stored );
		// The key contains the word Authorization, so its value is masked as well.
		$decoded = json_decode( $stored, true );
		$this->assertSame( Redactor::MASK, $decoded['Authorization: [redacted]'] );
		$this->assertSame( Redactor::MASK, $decoded['outer']['Authorization: [redacted]'] );
	}

	/**
	 * Two keys that redact to the same text both keep their values. The later one gets #2.
	 */
	public function test_two_keys_that_redact_to_the_same_text_both_keep_their_values(): void {
		$user_id = self::factory()->user->create();

		$this->log(
			$this->entry(
				$user_id,
				Entry::RESULT_OK,
				'',
				array(
					self::GITHUB_TOKEN => 'first value',
					'ghp_ZYXWVUTSRQPONMLKJIHGFEDCBA9876543210' => 'second value',
					'Authorization: Bearer abcdefghijklmnop0123456789' => 'third value',
					'Authorization: Bearer zyxwvutsrqponml9876543210' => 'fourth value',
				)
			)
		);

		$decoded = json_decode( $this->latest_row( $user_id )['input'], true );
		$this->assertSame( 'first value', $decoded[ Redactor::MASK ] );
		$this->assertSame( 'second value', $decoded[ Redactor::MASK . '#2' ] );
		// The Authorization keys contain a sensitive word, so their values are masked. What matters is that both are kept.
		$this->assertSame( Redactor::MASK, $decoded['Authorization: [redacted]'] );
		$this->assertSame( Redactor::MASK, $decoded['Authorization: [redacted]#2'] );
		$this->assertCount( 4, $decoded, 'No key is lost.' );
	}

	/**
	 * Input over 256 KB is not scanned: it is stored as the summary, and a secret in it is absent. The payload is mostly
	 * control characters, which redaction removes. If the input were scanned, it would shrink under the limit and be
	 * stored as the redacted input instead of the summary.
	 */
	public function test_input_over_256_kilobytes_is_stored_as_the_summary_and_is_not_scanned(): void {
		$user_id = self::factory()->user->create();

		$this->log(
			$this->entry(
				$user_id,
				Entry::RESULT_OK,
				'',
				array(
					'body'     => str_repeat( "\x01", 50000 ) . ' Authorization: Bearer abcdefghijklmnop0123456789',
					'password' => 'Hunter2secret',
				)
			)
		);

		$stored  = $this->latest_row( $user_id )['input'];
		$decoded = json_decode( $stored, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['truncated'] );
		$this->assertGreaterThan( DbLogger::MAX_RAW_INPUT_BYTES, $decoded['bytes'] );
		$this->assertSame( array( 'body', 'password' ), $decoded['keys'] );
		$this->assertStringNotContainsString( 'abcdefghijklmnop0123456789', $stored );
		$this->assertStringNotContainsString( 'Hunter2secret', $stored );
	}

	/**
	 * The summary of an oversized input keeps its key names redacted: a token used as a key does not reach the summary.
	 */
	public function test_the_summary_of_an_oversized_input_redacts_its_key_names(): void {
		$user_id = self::factory()->user->create();

		$this->log( $this->entry( $user_id, Entry::RESULT_OK, '', array( self::GITHUB_TOKEN => str_repeat( 'a', 20000 ) ) ) );

		$stored  = $this->latest_row( $user_id )['input'];
		$decoded = json_decode( $stored, true );
		$this->assertTrue( $decoded['truncated'] );
		$this->assertSame( array( Redactor::MASK ), $decoded['keys'] );
		$this->assertStringNotContainsString( 'abcdefghijklmnopqrstuvwxyz0123456789', $stored );
	}

	/**
	 * Input nested deeper than the key depth limit does not break the stored JSON, and its innermost value is not stored.
	 */
	public function test_deeply_nested_input_is_stored_as_valid_json_without_its_innermost_value(): void {
		$user_id = self::factory()->user->create();
		$nested  = 'DEEP_VALUE_SHOULD_NOT_APPEAR';
		for ( $i = 0; $i < 25; $i++ ) {
			$nested = array( 'level' => $nested );
		}

		$this->log( $this->entry( $user_id, Entry::RESULT_OK, '', $nested ) );

		$stored = $this->latest_row( $user_id )['input'];
		$this->assertIsArray( json_decode( $stored, true ) );
		$this->assertStringNotContainsString( 'DEEP_VALUE_SHOULD_NOT_APPEAR', $stored );
	}

	/**
	 * The default prune pass deletes in batches of PRUNE_BATCH (1000) and stops on a short batch. 1,500 rows older than
	 * retention need two batches, and one write removes them all.
	 */
	public function test_one_write_prunes_more_than_one_batch_of_old_rows(): void {
		$user_id = self::factory()->user->create();
		for ( $i = 0; $i < 1500; $i++ ) {
			$this->insert_row(
				array(
					'user_id'    => $user_id,
					'created_at' => $this->days_ago( 31 ),
				)
			);
		}
		delete_option( 'steadhand_log_last_prune' );

		$this->assertTrue( $this->log( $this->entry( $user_id ) ) );

		$this->assertSame( 0, $this->old_rows_for( $user_id ), 'Every row past retention is gone after one write.' );
		$this->assertSame( 1, $this->count_rows( $user_id ), 'Only the new entry is left.' );
	}

	/**
	 * With a batch of 10 and a budget of 3 batches, a pass deletes at most 30 rows. The budget being spent leaves the
	 * marker unset, so each following write continues. A pass that ends on a short batch marks the day, and no further
	 * pruning happens that day.
	 */
	public function test_a_pass_that_spends_its_budget_is_continued_by_the_next_write_and_a_finished_pass_is_not_repeated(): void {
		$user_id = self::factory()->user->create();
		for ( $i = 0; $i < 100; $i++ ) {
			$this->insert_row(
				array(
					'user_id'    => $user_id,
					'created_at' => $this->days_ago( 31 ),
				)
			);
		}
		delete_option( 'steadhand_log_last_prune' );
		$logger = new DbLogger( 10, 3 );

		$this->assertTrue( $logger->log( $this->entry( $user_id ) ) );
		$this->assertSame( 70, $this->old_rows_for( $user_id ), 'The budget ran out: old rows remain.' );
		$this->assertFalse( get_option( 'steadhand_log_last_prune' ), 'Nothing is recorded when the budget runs out.' );

		$this->assertTrue( $logger->log( $this->entry( $user_id ) ) );
		$this->assertSame( 40, $this->old_rows_for( $user_id ), 'The next write continues pruning.' );

		$this->assertTrue( $logger->log( $this->entry( $user_id ) ) );
		$this->assertSame( 10, $this->old_rows_for( $user_id ) );

		$this->assertTrue( $logger->log( $this->entry( $user_id ) ) );
		$this->assertSame( 0, $this->old_rows_for( $user_id ), 'A pass that ends on a short batch finishes the job.' );
		$this->assertNotFalse( get_option( 'steadhand_log_last_prune' ), 'The finished pass is recorded.' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_row(
				array(
					'user_id'    => $user_id,
					'created_at' => $this->days_ago( 31 ),
				)
			);
		}
		$this->assertTrue( $logger->log( $this->entry( $user_id ) ) );
		$this->assertSame( 5, $this->old_rows_for( $user_id ), 'No further pruning that day.' );
	}

	/**
	 * A count of the user's rows older than the 30-day retention.
	 *
	 * @param int $user_id User ID.
	 */
	private function old_rows_for( int $user_id ): int {
		global $wpdb;

		$table = \Steadhand\Log\Schema::table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the change-log table directly.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at < %s", $user_id, $this->days_ago( 30 ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is fixed.
		);
	}

	/**
	 * Stores refused calls for one user.
	 *
	 * @param int $user_id User ID.
	 * @param int $times   How many refusals.
	 */
	private function refuse( int $user_id, int $times ): void {
		for ( $i = 0; $i < $times; $i++ ) {
			$this->log( $this->entry( $user_id, Entry::RESULT_BLOCKED ) );
		}
	}

	/**
	 * How many rows for one user carry a given message.
	 *
	 * @param int    $user_id User ID.
	 * @param string $message Message.
	 */
	private function count_messages( int $user_id, string $message ): int {
		global $wpdb;

		$table = \Steadhand\Log\Schema::table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the change-log table directly.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND message = %s", $user_id, $message ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is fixed.
		);
	}

	public function test_a_successful_call_is_stored_even_while_refusals_are_throttled(): void {
		$user_id = self::factory()->user->create();
		for ( $i = 0; $i < DbLogger::REFUSALS_PER_MINUTE + 2; $i++ ) {
			$this->log( $this->entry( $user_id, Entry::RESULT_BLOCKED ) );
		}
		$before = $this->count_rows( $user_id );

		$this->assertTrue( $this->log( $this->entry( $user_id, Entry::RESULT_OK, 'done' ) ) );

		$this->assertSame( $before + 1, $this->count_rows( $user_id ) );
		$this->assertSame( Entry::RESULT_OK, $this->latest_row( $user_id )['result'] );
	}

	public function test_another_users_refusals_do_not_throttle_this_user(): void {
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();
		for ( $i = 0; $i < DbLogger::REFUSALS_PER_MINUTE + 1; $i++ ) {
			$this->log( $this->entry( $first, Entry::RESULT_BLOCKED ) );
		}

		$this->assertTrue( $this->log( $this->entry( $second, Entry::RESULT_BLOCKED, 'second user' ) ) );

		$this->assertSame( 1, $this->count_rows( $second ) );
		$this->assertSame( 'second user', $this->latest_row( $second )['message'] );
	}

	public function test_rows_older_than_thirty_days_are_pruned_and_newer_rows_are_kept(): void {
		$user_id = self::factory()->user->create();
		$old     = $this->insert_row(
			array(
				'user_id'    => $user_id,
				'created_at' => $this->days_ago( 31 ),
			)
		);
		$recent  = $this->insert_row(
			array(
				'user_id'    => $user_id,
				'created_at' => $this->days_ago( 29 ),
			)
		);
		delete_option( 'steadhand_log_last_prune' );

		$this->assertTrue( $this->log( $this->entry( $user_id ) ) );

		$this->assertFalse( $this->row_exists( $old ), 'A row past 30 days is pruned.' );
		$this->assertTrue( $this->row_exists( $recent ), 'A row within 30 days is kept.' );
	}

	public function test_the_retention_filter_changes_the_window(): void {
		$user_id = self::factory()->user->create();
		add_filter( 'steadhand_log_retention_days', static fn(): int => 7 );
		$ten_days = $this->insert_row(
			array(
				'user_id'    => $user_id,
				'created_at' => $this->days_ago( 10 ),
			)
		);
		$three    = $this->insert_row(
			array(
				'user_id'    => $user_id,
				'created_at' => $this->days_ago( 3 ),
			)
		);
		delete_option( 'steadhand_log_last_prune' );

		$this->log( $this->entry( $user_id ) );

		$this->assertFalse( $this->row_exists( $ten_days ) );
		$this->assertTrue( $this->row_exists( $three ) );
	}

	/**
	 * Every value that is not a whole number of at least 1 falls back to the default of 30 days.
	 */
	public function test_an_invalid_retention_value_falls_back_to_thirty_days(): void {
		foreach ( array( 0, -1, 'abc', null, '0', '-5', 2.5 ) as $value ) {
			$user_id = self::factory()->user->create();
			$filter  = static fn(): mixed => $value;
			add_filter( 'steadhand_log_retention_days', $filter );
			$old     = $this->insert_row(
				array(
					'user_id'    => $user_id,
					'created_at' => $this->days_ago( 31 ),
				)
			);
			$recent  = $this->insert_row(
				array(
					'user_id'    => $user_id,
					'created_at' => $this->days_ago( 29 ),
				)
			);
			delete_option( 'steadhand_log_last_prune' );

			$this->log( $this->entry( $user_id ) );

			$this->assertFalse( $this->row_exists( $old ), 'Retention ' . (string) wp_json_encode( $value ) . ' must fall back to 30.' );
			$this->assertTrue( $this->row_exists( $recent ), 'Retention ' . (string) wp_json_encode( $value ) . ' must fall back to 30.' );
			remove_filter( 'steadhand_log_retention_days', $filter );
		}
	}

	public function test_a_throwing_retention_filter_falls_back_to_thirty_days_and_the_call_succeeds(): void {
		$user_id = self::factory()->user->create();
		add_filter(
			'steadhand_log_retention_days',
			static function (): int {
				throw new \RuntimeException( 'Filter failed.' );
			}
		);
		$old = $this->insert_row(
			array(
				'user_id'    => $user_id,
				'created_at' => $this->days_ago( 31 ),
			)
		);
		delete_option( 'steadhand_log_last_prune' );

		$this->assertTrue( $this->log( $this->entry( $user_id ) ) );

		$this->assertFalse( $this->row_exists( $old ) );
	}

	public function test_the_retention_is_capped_at_3650_days(): void {
		$user_id = self::factory()->user->create();
		add_filter( 'steadhand_log_retention_days', static fn(): int => 99999 );
		$too_old = $this->insert_row(
			array(
				'user_id'    => $user_id,
				'created_at' => $this->days_ago( 4000 ),
			)
		);
		$kept    = $this->insert_row(
			array(
				'user_id'    => $user_id,
				'created_at' => $this->days_ago( 3000 ),
			)
		);
		delete_option( 'steadhand_log_last_prune' );

		$this->log( $this->entry( $user_id ) );

		$this->assertFalse( $this->row_exists( $too_old ), 'Beyond the cap the row is pruned.' );
		$this->assertTrue( $this->row_exists( $kept ), 'Within the cap the row is kept.' );
	}

	public function test_pruning_runs_at_most_once_a_day(): void {
		$user_id = self::factory()->user->create();
		$old     = $this->insert_row(
			array(
				'user_id'    => $user_id,
				'created_at' => $this->days_ago( 40 ),
			)
		);

		update_option( 'steadhand_log_last_prune', time(), false );
		$this->log( $this->entry( $user_id ) );
		$this->assertTrue( $this->row_exists( $old ), 'Pruned within the last day: nothing is deleted.' );

		update_option( 'steadhand_log_last_prune', time() - DAY_IN_SECONDS - 60, false );
		$this->log( $this->entry( $user_id ) );
		$this->assertFalse( $this->row_exists( $old ), 'A day later pruning runs again.' );
	}

	public function test_a_failed_prune_does_not_fail_the_logged_call(): void {
		$user_id = self::factory()->user->create();
		delete_option( 'steadhand_log_last_prune' );
		add_filter(
			'query',
			static fn( string $query ): string => str_starts_with( $query, 'DELETE FROM' )
				? 'DELETE FROM steadhand_no_such_table_for_prune WHERE 1 = 0'
				: $query
		);

		$this->assertTrue( $this->log( $this->entry( $user_id ) ), 'The entry is stored; the prune failure is not the caller\'s.' );
		$this->assertSame( 1, $this->count_rows( $user_id ) );
	}

	public function test_an_insert_failure_returns_false_and_prints_nothing(): void {
		$user_id = self::factory()->user->create();
		$this->make_inserts_fail();

		ob_start();
		$stored = $this->log( $this->entry( $user_id, Entry::RESULT_BLOCKED, 'refused' ) );
		$output = (string) ob_get_clean();

		$this->assertFalse( $stored );
		$this->assertSame( '', $output, 'No SQL may reach the response.' );
	}

	public function test_the_stored_action_fires_once_with_the_id_and_the_redacted_row(): void {
		$user_id = self::factory()->user->create();
		$calls   = array();
		add_action(
			'steadhand_log_entry_stored',
			static function ( int $id, array $row ) use ( &$calls ): void {
				$calls[] = array( $id, $row );
			},
			10,
			2
		);

		$this->assertTrue( $this->log( $this->entry( $user_id, Entry::RESULT_OK, '', array( 'password' => 'Hunter2secret' ) ) ) );

		$this->assertCount( 1, $calls );
		list( $id, $row ) = $calls[0];
		$this->assertSame( (int) $this->latest_row( $user_id )['id'], $id );
		$this->assertSame( $id, $row['id'] );
		$this->assertArrayHasKey( 'created_at', $row );
		$this->assertStringNotContainsString( 'Hunter2secret', (string) $row['input'] );
	}

	/**
	 * The after-state snapshot is stored by log() and by the two-step completion, and the stored-action listener gets it
	 * as an int, next to snapshot_id.
	 */
	public function test_the_after_snapshot_is_stored_on_both_paths_and_given_to_the_listener_as_an_int(): void {
		$user_id = self::factory()->user->create();
		$seen    = array();
		add_action(
			'steadhand_log_entry_stored',
			static function ( int $id, array $row ) use ( &$seen ): void {
				$seen[ $id ] = $row;
			},
			10,
			2
		);

		$entry = new Entry( $user_id, '', 'steadhand/update-post', Entry::RESULT_OK, '', array(), 'post', 3, '', 11, true, 12 );
		$this->assertTrue( $this->log( $entry ) );
		$logged = $this->latest_row( $user_id );
		$this->assertSame( '12', (string) $logged['after_snapshot_id'] );

		$logger = new DbLogger();
		$id     = $logger->begin( new Entry( $user_id, '', 'steadhand/update-post', Entry::RESULT_ERROR ) );
		$this->assertGreaterThan( 0, $id );
		$this->assertTrue( $logger->complete( $id, $entry ) );

		$completed = $this->stored( $id );
		$this->assertSame( '12', (string) $completed['after_snapshot_id'] );
		$this->assertSame( '11', (string) $completed['snapshot_id'] );
		$this->assertSame( 12, $seen[ $id ]['after_snapshot_id'] ?? null, 'The listener gets an int, as for snapshot_id.' );
		$this->assertSame( 11, $seen[ $id ]['snapshot_id'] ?? null );
	}

	public function test_a_listener_that_throws_does_not_change_the_outcome(): void {
		$user_id = self::factory()->user->create();
		add_action(
			'steadhand_log_entry_stored',
			static function (): void {
				throw new \RuntimeException( 'Listener failed.' );
			}
		);

		$this->assertTrue( $this->log( $this->entry( $user_id ) ) );
		$this->assertSame( 1, $this->count_rows( $user_id ) );
	}

	/**
	 * A timestamp the given number of days before now, in the table's UTC format.
	 *
	 * @param int $days Days ago.
	 */
	private function days_ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}
}
