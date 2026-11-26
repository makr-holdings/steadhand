<?php
/**
 * Commits one change-log row on purpose, so that the next run can show that the bootstrap clears it.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Commits one change-log row for user 5 and leaves it there. That is safe to keep in the suite permanently: the next
 * run's bootstrap (tests/bootstrap.php) deletes every row of the change-log table before any test starts. This test checks
 * that at its own start, so a run that begins with a user-5 row left by an earlier run fails here.
 *
 * The name sorts this file last in tests/Integration, because PHPUnit runs the files of a directory in sorted order. No
 * other test of the run therefore sees the row this test commits.
 */
final class ZzCommittedRowProofTest extends LogTestCase {

	public function test_the_row_of_an_earlier_run_is_gone_and_one_row_is_committed_on_purpose(): void {
		global $wpdb;

		$this->assertSame( 0, $this->count_rows( 5 ), 'A row for user 5 was committed by an earlier run, and the bootstrap should have removed it.' );

		$this->insert_row(
			array(
				'user_id' => 5,
				'message' => 'Committed on purpose by ZzCommittedRowProofTest.',
			)
		);
		$this->assertSame( 1, $this->count_rows( 5 ) );

		// The test base turns DROP TABLE into DROP TEMPORARY TABLE, which does not commit. The filter is removed for this one
		// statement, so the statement is real DDL, and DDL commits the open transaction, with the row in it. The table name
		// is one that does not exist, so nothing is dropped.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'steadhand_commit_marker' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- A DDL statement on a table that does not exist, on purpose, to commit the row above.
	}
}
