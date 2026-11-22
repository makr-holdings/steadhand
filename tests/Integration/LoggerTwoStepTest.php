<?php
/**
 * The two-step log write (begin, complete) and the recent-count query, with the real DbLogger.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\Schema;

/**
 * Covers SPEC §7: a write reserves its row before it runs, and the outcome is written to that row afterwards.
 */
final class LoggerTwoStepTest extends LogTestCase {

	private const WRITE = 'steadhand/probe-write';

	private const PENDING = 'The call started, but no outcome was recorded.';

	/**
	 * Reserves a row for a write by the given user, and returns its ID.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Input to record.
	 */
	private function reserve( int $user_id, array $input = array() ): int {
		$id = ( new DbLogger() )->begin( new Entry( $user_id, 'Test agent', self::WRITE, Entry::RESULT_OK, '', $input ) );
		$this->assertGreaterThan( 0, $id, 'begin() must return a row ID.' );

		return $id;
	}

	public function test_begin_stores_an_error_row_with_the_pending_message_whatever_result_was_passed(): void {
		$user = self::factory()->user->create();
		$id   = ( new DbLogger() )->begin( new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_BLOCKED, 'Something else', array( 'title' => 'x' ) ) );

		$this->assertGreaterThan( 0, $id );
		$row = $this->stored( $id );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( self::PENDING, $row['message'] );
		$this->assertSame( self::WRITE, $row['ability'] );
		$this->assertSame( $user, (int) $row['user_id'] );
		$this->assertSame( '{"title":"x"}', $row['input'] );
	}

	public function test_complete_sets_the_final_values_on_the_reserved_row(): void {
		$user = self::factory()->user->create();
		$id   = $this->reserve( $user );

		$ok = ( new DbLogger() )->complete(
			$id,
			new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_FIXED, '', array(), 'post', 5, "steadhand/x: changed\nsteadhand/y: noted", 9, true )
		);

		$this->assertTrue( $ok );
		$row = $this->stored( $id );
		$this->assertSame( Entry::RESULT_FIXED, $row['result'] );
		$this->assertSame( '', $row['message'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( 5, (int) $row['object_id'] );
		$this->assertSame( "steadhand/x: changed\nsteadhand/y: noted", $row['guardrail_notes'] );
		$this->assertSame( 9, (int) $row['snapshot_id'] );
		$this->assertSame( 1, (int) $row['undoable'] );
	}

	public function test_complete_with_an_error_outcome_stores_its_message(): void {
		$user = self::factory()->user->create();
		$id   = $this->reserve( $user );

		$this->assertTrue( ( new DbLogger() )->complete( $id, new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_ERROR, 'The ability failed.' ) ) );

		$row = $this->stored( $id );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( 'The ability failed.', $row['message'] );
	}

	public function test_complete_on_a_row_of_another_user_fails_and_changes_nothing(): void {
		$owner = self::factory()->user->create();
		$other = self::factory()->user->create();
		$id    = $this->reserve( $owner );

		$ok = ( new DbLogger() )->complete( $id, new Entry( $other, 'Other agent', self::WRITE, Entry::RESULT_OK ) );

		$this->assertFalse( $ok );
		$this->assertSame( self::PENDING, $this->stored( $id )['message'] );
		$this->assertSame( Entry::RESULT_ERROR, $this->stored( $id )['result'] );
	}

	public function test_complete_with_an_unknown_id_fails(): void {
		$user = self::factory()->user->create();

		$this->assertFalse( ( new DbLogger() )->complete( 999999999, new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_OK ) ) );
		$this->assertFalse( ( new DbLogger() )->complete( 0, new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_OK ) ) );
	}

	public function test_complete_refuses_a_blocked_outcome(): void {
		$user = self::factory()->user->create();
		$id   = $this->reserve( $user );

		$this->assertFalse( ( new DbLogger() )->complete( $id, new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_BLOCKED, 'Refused.' ) ) );
		$this->assertSame( Entry::RESULT_ERROR, $this->stored( $id )['result'] );
	}

	public function test_complete_refuses_a_malformed_ability_name(): void {
		$user = self::factory()->user->create();
		$id   = $this->reserve( $user );

		$this->assertFalse( ( new DbLogger() )->complete( $id, new Entry( $user, 'Test agent', 'get-post', Entry::RESULT_OK ) ) );
	}

	public function test_begin_refuses_a_malformed_ability_name(): void {
		$user = self::factory()->user->create();

		$this->assertSame( 0, ( new DbLogger() )->begin( new Entry( $user, 'Test agent', 'steadhand/Bad Name', Entry::RESULT_OK ) ) );
		$this->assertSame( 0, ( new DbLogger() )->begin( new Entry( $user, 'Test agent', 'other/post', Entry::RESULT_OK ) ) );
	}

	public function test_redaction_and_caps_apply_to_the_message_and_notes_written_by_complete(): void {
		$user = self::factory()->user->create();
		$id   = $this->reserve( $user );

		( new DbLogger() )->complete(
			$id,
			new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_ERROR, 'password=hunter2 was rejected', array(), '', 0, 'token=abc123secret' )
		);

		$row = $this->stored( $id );
		$this->assertStringNotContainsString( 'hunter2', $row['message'] );
		$this->assertStringContainsString( 'password=[redacted]', $row['message'] );
		$this->assertStringNotContainsString( 'abc123secret', $row['guardrail_notes'] );
	}

	public function test_complete_caps_an_oversized_message(): void {
		$user = self::factory()->user->create();
		$id   = $this->reserve( $user );

		( new DbLogger() )->complete( $id, new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_ERROR, str_repeat( 'x', 5000 ) ) );

		$this->assertLessThanOrEqual( DbLogger::MAX_MESSAGE_BYTES, strlen( $this->stored( $id )['message'] ) );
	}

	public function test_begin_is_never_throttled_even_after_refusals_are_dropped(): void {
		$user = self::factory()->user->create();
		$log  = new DbLogger();
		for ( $i = 0; $i < DbLogger::REFUSALS_PER_MINUTE + 5; $i++ ) {
			$log->log( new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_BLOCKED, 'Refused.' ) );
		}
		$this->assertSame( DbLogger::REFUSALS_PER_MINUTE + 1, $this->count_rows( $user ), 'Control: the refusal throttle is in force.' );

		$id = $log->begin( new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_OK ) );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( self::PENDING, $this->stored( $id )['message'] );
	}

	public function test_the_stored_action_fires_once_on_complete_and_not_on_begin(): void {
		$user   = self::factory()->user->create();
		$fired  = array();
		$record = static function ( int $id, array $row ) use ( &$fired ): void {
			$fired[] = array( $id, $row );
		};
		add_action( 'steadhand_log_entry_stored', $record, 10, 2 );

		try {
			$id = $this->reserve( $user );
			$this->assertCount( 0, $fired, 'begin() must not announce the row.' );

			( new DbLogger() )->complete( $id, new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_OK ) );
		} finally {
			remove_action( 'steadhand_log_entry_stored', $record, 10 );
		}

		$this->assertCount( 1, $fired, 'complete() announces the final row once.' );
		$this->assertSame( $id, $fired[0][0] );
		$this->assertSame( Entry::RESULT_OK, $fired[0][1]['result'] );
	}

	public function test_begin_fails_when_the_insert_fails(): void {
		$user = self::factory()->user->create();
		$this->make_inserts_fail();

		ob_start();
		$id     = ( new DbLogger() )->begin( new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_OK ) );
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $id );
		$this->assertSame( '', $output, 'A failed insert prints nothing.' );
	}

	public function test_complete_fails_when_the_update_fails(): void {
		$user  = self::factory()->user->create();
		$id    = $this->reserve( $user );
		$table = Schema::table();
		add_filter(
			'query',
			static function ( string $query ) use ( $table ): string {
				return str_starts_with( $query, 'UPDATE `' . $table . '`' )
					? str_replace( 'UPDATE `' . $table . '`', 'UPDATE `' . $table . '_missing`', $query )
					: $query;
			}
		);

		$this->assertFalse( ( new DbLogger() )->complete( $id, new Entry( $user, 'Test agent', self::WRITE, Entry::RESULT_OK ) ) );
		$this->assertSame( Entry::RESULT_ERROR, $this->stored( $id )['result'] );
	}

	public function test_count_recent_counts_only_listed_abilities_with_ok_fixed_or_error_for_this_user_in_the_window(): void {
		$user  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$old   = gmdate( 'Y-m-d H:i:s', time() - 120 );

		$this->insert_row(
			array(
				'user_id'    => $user,
				'ability'    => self::WRITE,
				'result'     => Entry::RESULT_OK,
				'created_at' => $now,
			)
		);
		$this->insert_row(
			array(
				'user_id'    => $user,
				'ability'    => self::WRITE,
				'result'     => Entry::RESULT_FIXED,
				'created_at' => $now,
			)
		);
		$this->insert_row(
			array(
				'user_id'    => $user,
				'ability'    => self::WRITE,
				'result'     => Entry::RESULT_ERROR,
				'created_at' => $now,
			)
		);
		// Not counted: a refusal, another ability, another user, and a row outside the window.
		$this->insert_row(
			array(
				'user_id'    => $user,
				'ability'    => self::WRITE,
				'result'     => Entry::RESULT_BLOCKED,
				'created_at' => $now,
			)
		);
		$this->insert_row(
			array(
				'user_id'    => $user,
				'ability'    => 'steadhand/get-site-info',
				'result'     => Entry::RESULT_OK,
				'created_at' => $now,
			)
		);
		$this->insert_row(
			array(
				'user_id'    => $other,
				'ability'    => self::WRITE,
				'result'     => Entry::RESULT_OK,
				'created_at' => $now,
			)
		);
		$this->insert_row(
			array(
				'user_id'    => $user,
				'ability'    => self::WRITE,
				'result'     => Entry::RESULT_OK,
				'created_at' => $old,
			)
		);

		$log = new DbLogger();

		$this->assertSame( 3, $log->count_recent( $user, array( self::WRITE ), 60 ) );
		$this->assertSame( 0, $log->count_recent( $user, array( 'steadhand/update-menu' ), 60 ) );
		$this->assertSame( 4, $log->count_recent( $user, array( self::WRITE, 'steadhand/get-site-info' ), 60 ) );
		$this->assertSame( 4, $log->count_recent( $user, array( self::WRITE ), 300 ), 'The old row falls inside a longer window.' );
	}

	public function test_count_recent_returns_zero_for_an_empty_list(): void {
		$user = self::factory()->user->create();
		$this->insert_row(
			array(
				'user_id' => $user,
				'ability' => self::WRITE,
				'result'  => Entry::RESULT_OK,
			)
		);

		$this->assertSame( 0, ( new DbLogger() )->count_recent( $user, array(), 60 ) );
	}

	public function test_count_recent_fails_closed_for_a_malformed_ability_name(): void {
		$user = self::factory()->user->create();

		$this->assertSame( PHP_INT_MAX, ( new DbLogger() )->count_recent( $user, array( 'update-post' ), 60 ) );
		$this->assertSame( PHP_INT_MAX, ( new DbLogger() )->count_recent( $user, array( self::WRITE, 'steadhand/Bad' ), 60 ) );
	}

	public function test_count_recent_returns_php_int_max_when_the_query_fails(): void {
		$user  = self::factory()->user->create();
		$table = Schema::table();
		add_filter(
			'query',
			static function ( string $query ) use ( $table ): string {
				return str_starts_with( $query, 'SELECT COUNT(*) FROM ' . $table )
					? str_replace( 'FROM ' . $table, 'FROM ' . $table . '_missing', $query )
					: $query;
			}
		);

		$this->assertSame( PHP_INT_MAX, ( new DbLogger() )->count_recent( $user, array( self::WRITE ), 60 ) );
	}
}
