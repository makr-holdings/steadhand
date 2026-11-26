<?php
/**
 * The change-log table: its columns, installation, upgrade and removal.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Diagnostics\SelfCheck;
use Steadhand\Log\Schema;

/**
 * Covers SPEC §7 (the change log) and the table lifecycle that activation, boot and uninstall drive.
 */
final class SchemaTest extends LogTestCase {

	/**
	 * Every test starts from the baseline: both tables ready, the version recorded, no back-off and no prune marker. The
	 * tests that run real DDL end in restore_baseline(), but a run that was interrupted can still leave committed state
	 * behind, and no test may inherit it. The check reads only, so a healthy baseline costs no writes and no commit. Only
	 * a broken baseline is restored, and that restore commits, which is the point of it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! $this->is_baseline() ) {
			$this->restore_baseline();
			$this->assertTrue( $this->is_baseline(), 'The baseline could not be restored before the test.' );
		}
	}

	public function test_the_table_exists_after_install_with_every_column(): void {
		Schema::install();

		$this->assertTrue( Schema::exists() );
		$this->assertSame( $this->expected_columns(), $this->column_names() );
	}

	public function test_install_is_idempotent_and_records_the_version(): void {
		Schema::install();
		Schema::install();

		$this->assertTrue( Schema::exists() );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );
		$this->assertSame( $this->expected_columns(), $this->column_names() );
	}

	public function test_maybe_upgrade_installs_when_the_version_option_is_missing(): void {
		delete_option( Schema::OPTION );

		Schema::maybe_upgrade();

		$this->assertTrue( Schema::exists() );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );
	}

	public function test_maybe_upgrade_installs_when_the_version_option_is_stale(): void {
		update_option( Schema::OPTION, '0' );

		Schema::maybe_upgrade();

		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );
		$this->assertTrue( Schema::exists() );
	}

	/**
	 * A table made with the layout before after_snapshot_id (version 1) gains the column when the version is stale, and
	 * its existing rows are kept, with the new column at 0.
	 */
	public function test_a_table_with_the_old_layout_gains_the_column_and_keeps_its_rows(): void {
		global $wpdb;

		$this->let_ddl_reach_the_table();
		$table = Schema::table();

		try {
			Schema::uninstall();

			$this->create_version_one_table();
			$this->assertNotContains( 'after_snapshot_id', $this->column_names(), 'The fixture must be the old layout.' );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture written straight to the change-log table.
				$table,
				array(
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
					'user_id'     => 5,
					'ability'     => 'steadhand/update-post',
					'result'      => 'ok',
					'snapshot_id' => 7,
					'undoable'    => 1,
				)
			);
			$id = (int) $wpdb->insert_id;

			update_option( Schema::OPTION, '1' );
			Schema::maybe_upgrade();

			$this->assertSame( $this->expected_columns(), $this->column_names() );
			$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );

			$kept = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The table name is fixed.
			$this->assertIsArray( $kept, 'The row written before the upgrade must still exist.' );
			$this->assertSame( 'steadhand/update-post', $kept['ability'] );
			$this->assertSame( '7', (string) $kept['snapshot_id'] );
			$this->assertSame( '0', (string) $kept['after_snapshot_id'], 'A row from before the upgrade has no after-state.' );
			// Rows from before the upgrade have no after-state, so none of them may stay undoable (SPEC §7).
			$this->assertSame( '0', (string) $kept['undoable'] );
		} finally {
			$this->restore_baseline();
		}

		$this->assertSame( $this->expected_columns(), $this->column_names() );
	}

	public function test_ready_is_true_after_install(): void {
		Schema::install();

		$this->assertTrue( Schema::ready() );
	}

	/**
	 * A table without a column the code writes is not ready, although it exists. The inserts would fail on it.
	 */
	public function test_ready_is_false_while_the_table_lacks_a_column_the_code_writes(): void {
		$this->let_ddl_reach_the_table();

		try {
			Schema::uninstall();
			$this->create_version_one_table();

			$this->assertTrue( Schema::exists() );
			$this->assertFalse( Schema::ready() );
		} finally {
			$this->restore_baseline();
		}

		$this->assertTrue( Schema::ready() );
	}

	/**
	 * An upgrade whose ALTER is refused records no version, sets the back-off, and the self-check reports the log as not
	 * ready. The next request does not retry while the back-off is set.
	 */
	public function test_a_refused_upgrade_records_no_version_sets_the_back_off_and_the_self_check_reports_not_ready(): void {
		$this->let_ddl_reach_the_table();
		$alters = 0;
		$filter = null;

		try {
			Schema::uninstall();
			$this->create_version_one_table();
			$this->insert_version_one_row();
			update_option( Schema::OPTION, '1' );

			$filter = $this->fail_the_after_snapshot_alter( $alters );

			ob_start();
			Schema::maybe_upgrade();
			$output = (string) ob_get_clean();

			$this->assertSame( '', $output, 'No database error may be printed.' );
			$this->assertSame( 1, $alters, 'The upgrade ran one ALTER, and it was refused.' );
			$this->assertSame( '1', get_option( Schema::OPTION ), 'A refused upgrade must not record version 2.' );
			$this->assertNotFalse( get_transient( Schema::RETRY_TRANSIENT ), 'A refused upgrade sets the back-off.' );
			$this->assertFalse( Schema::ready() );
			$this->assertFalse( SelfCheck::report()['change_log_ready'], 'The self-check must report the log as not ready.' );

			Schema::maybe_upgrade();
			$this->assertSame( 1, $alters, 'maybe_upgrade() runs no ALTER while the back-off is set.' );
		} finally {
			if ( null !== $filter ) {
				remove_filter( 'query', $filter );
			}
			$this->restore_baseline();
		}

		$this->assertTrue( Schema::ready() );
	}

	/**
	 * After a refused upgrade, the next upgrade that succeeds records the version, and sets the rows from before the
	 * upgrade to not undoable.
	 */
	public function test_a_later_successful_upgrade_records_the_version_and_clears_the_pre_upgrade_undo_flags(): void {
		global $wpdb;

		$this->let_ddl_reach_the_table();
		$alters = 0;
		$filter = null;

		try {
			Schema::uninstall();
			$this->create_version_one_table();
			$id = $this->insert_version_one_row();
			update_option( Schema::OPTION, '1' );

			$filter = $this->fail_the_after_snapshot_alter( $alters );
			Schema::maybe_upgrade();
			remove_filter( 'query', $filter );
			$filter = null;

			// The back-off is an hour. Clearing it stands for the hour passing.
			delete_transient( Schema::RETRY_TRANSIENT );
			Schema::maybe_upgrade();

			$this->assertTrue( Schema::ready() );
			$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );

			$table = Schema::table();
			$kept  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The table name is fixed.
			$this->assertSame( '0', (string) $kept['undoable'] );
		} finally {
			if ( null !== $filter ) {
				remove_filter( 'query', $filter );
			}
			$this->restore_baseline();
		}
	}

	/**
	 * The reset touches only rows that are undoable without an after-state. A row with an after-state keeps its flag, and
	 * running the reset again changes nothing (it is idempotent).
	 */
	public function test_the_reset_keeps_rows_with_an_after_state_and_is_idempotent(): void {
		global $wpdb;

		Schema::install();
		$table = Schema::table();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture written straight to the change-log table.
			$table,
			array(
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
				'ability'           => 'steadhand/update-post',
				'result'            => 'ok',
				'snapshot_id'       => 3,
				'after_snapshot_id' => 5,
				'undoable'          => 1,
			)
		);
		$kept = (int) $wpdb->insert_id;

		Schema::install();
		Schema::install();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT undoable FROM {$table} WHERE id = %d", $kept ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The table name is fixed.
		$this->assertSame( '1', (string) $row['undoable'], 'A row with an after-state keeps its undoable flag.' );
	}

	public function test_a_missing_table_is_reported_as_missing(): void {
		$this->make_table_look_missing();

		$this->assertFalse( Schema::exists() );
	}

	/**
	 * Drops and recreates the real table. The WordPress test base turns CREATE and DROP TABLE into temporary-table
	 * statements, so those filters are removed for this test and the real table is restored afterwards. DDL commits
	 * the test transaction, which is why the table is recreated here rather than left to rollback.
	 */
	public function test_uninstall_drops_the_table_and_both_options_and_install_restores_it(): void {
		update_option( 'steadhand_log_last_prune', time(), false );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			Schema::uninstall();

			$this->assertFalse( Schema::exists(), 'uninstall must drop the table.' );
			$this->assertFalse( get_option( Schema::OPTION ), 'uninstall must delete the version option.' );
			$this->assertFalse( get_option( 'steadhand_log_last_prune' ), 'uninstall must delete the prune option.' );
		} finally {
			$this->restore_baseline();
		}

		$this->assertTrue( Schema::exists(), 'The table must be back for the other tests.' );
		$this->assertSame( $this->expected_columns(), $this->column_names() );
	}

	/**
	 * A failed create writes no version, sets the back-off, and prints nothing. While the back-off is set, maybe_upgrade()
	 * runs no CREATE, and prints nothing either. The real table is restored afterwards.
	 */
	public function test_a_failed_create_writes_no_version_sets_the_back_off_and_prints_nothing(): void {
		$this->let_ddl_reach_the_table();
		$creates = 0;
		$filter  = $this->fail_creates( $creates );

		try {
			Schema::uninstall();

			ob_start();
			Schema::install();
			$output = (string) ob_get_clean();

			$this->assertSame( '', $output, 'No database error may be printed.' );
			$this->assertSame( 2, $creates, 'Install ran one CREATE for each table.' );
			$this->assertFalse( Schema::exists() );
			$this->assertFalse( get_option( Schema::OPTION ), 'A failed create must not record the layout version.' );
			$this->assertNotFalse( get_transient( Schema::RETRY_TRANSIENT ), 'A failed create sets the back-off.' );

			ob_start();
			Schema::maybe_upgrade();
			$output = (string) ob_get_clean();

			$this->assertSame( '', $output );
			$this->assertSame( 2, $creates, 'maybe_upgrade must run no CREATE while the back-off is set.' );
		} finally {
			remove_filter( 'query', $filter );
			$this->restore_baseline();
		}

		$this->assertTrue( Schema::exists(), 'The real table must be back for the other tests.' );
		$this->assertFalse( get_transient( Schema::RETRY_TRANSIENT ), 'A successful install clears the back-off.' );
	}

	/**
	 * Activation calls install() directly, and install() always tries, whatever the back-off says.
	 */
	public function test_install_still_tries_while_the_back_off_is_set(): void {
		$this->let_ddl_reach_the_table();
		$creates = 0;
		$filter  = $this->fail_creates( $creates );

		try {
			Schema::uninstall();
			set_transient( Schema::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );
			Schema::install();

			$this->assertSame( 2, $creates, 'install() tries even while the back-off is set.' );
		} finally {
			remove_filter( 'query', $filter );
			$this->restore_baseline();
		}

		$this->assertTrue( Schema::exists() );
	}

	/**
	 * Uninstall removes the back-off as well as the table and options.
	 */
	public function test_uninstall_removes_the_back_off(): void {
		$this->let_ddl_reach_the_table();
		set_transient( Schema::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );

		try {
			Schema::uninstall();

			$this->assertFalse( get_transient( Schema::RETRY_TRANSIENT ) );
		} finally {
			$this->restore_baseline();
		}

		$this->assertTrue( Schema::exists() );
	}

	/**
	 * A failed table check reports the table as missing and prints nothing.
	 */
	public function test_a_failed_table_check_prints_nothing_and_reports_the_table_missing(): void {
		$filter = static fn( string $query ): string => str_starts_with( $query, 'SHOW TABLES LIKE' ) ? 'THIS IS NOT SQL' : $query;
		add_filter( 'query', $filter );

		try {
			ob_start();
			$exists = Schema::exists();
			$output = (string) ob_get_clean();
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assertFalse( $exists );
		$this->assertSame( '', $output, 'No database error may be printed.' );
	}

	/**
	 * The indexes are the primary key, created_at, (user_id, created_at) and (user_id, id), which serves the per-agent
	 * queries. A result index is not created: no query filters on result alone. The table is rebuilt so that an older
	 * dev table with a result index does not hide the result.
	 */
	public function test_a_fresh_table_has_the_user_and_id_index_and_no_result_index(): void {
		global $wpdb;

		$this->let_ddl_reach_the_table();
		try {
			Schema::uninstall();
			Schema::install();

			$table = Schema::table();
			// The table name is fixed (see Schema::table()); SHOW INDEX cannot bind an identifier.
			$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads the table definition; the table name is fixed.
		} finally {
			$this->restore_baseline();
		}

		$keys = array_values( array_unique( array_column( (array) $rows, 'Key_name' ) ) );
		sort( $keys );
		$this->assertSame( array( 'PRIMARY', 'created_at', 'user_created', 'user_id_id' ), $keys );
	}

	/**
	 * Whether the committed state is the baseline: both tables ready, the version recorded, and no back-off and no prune
	 * marker. Reads only, so it writes nothing and commits nothing.
	 *
	 * @return bool
	 */
	private function is_baseline(): bool {
		return Schema::ready()
			&& Schema::snapshots_ready()
			&& Schema::DB_VERSION === get_option( Schema::OPTION )
			&& false === get_transient( Schema::RETRY_TRANSIENT )
			&& false === get_option( 'steadhand_log_last_prune' );
	}

	/**
	 * Puts the real tables and the rest of the state back to the baseline, and commits it. Every test that runs real DDL
	 * calls this from its finally block, whatever happened in the test.
	 *
	 * The temporary-table filters are removed first, because under them CREATE and DROP touch only temporary tables. Then
	 * uninstall() drops both tables, which removes every row the test committed (a DROP commits the rows written before
	 * it), and it deletes the version option, the back-off and the prune marker. install() records the version only after
	 * its last CREATE TABLE, so that write would stay in the open transaction and be rolled back at tearDown. The version
	 * is therefore written before install(): the CREATE TABLE statements that follow commit it, with the rest.
	 *
	 * @return void
	 */
	private function restore_baseline(): void {
		$this->let_ddl_reach_the_table();

		Schema::uninstall();
		update_option( Schema::OPTION, Schema::DB_VERSION );
		Schema::install();
	}

	/**
	 * Removes the test base's temporary-table rewrites, so that CREATE and DROP reach the real table. The callers rebuild
	 * the table in a finally block.
	 */
	private function let_ddl_reach_the_table(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Makes every CREATE TABLE statement invalid, and counts them. Returns the filter, so the caller can remove it.
	 *
	 * @param int $creates Set by reference to the number of CREATE statements seen. Read it after the call.
	 * @return \Closure
	 */
	private function fail_creates( int &$creates ): \Closure {
		$filter = static function ( string $query ) use ( &$creates ): string {
			if ( str_starts_with( $query, 'CREATE TABLE' ) ) {
				++$creates;
				return 'THIS IS NOT SQL';
			}

			return $query;
		};
		add_filter( 'query', $filter );

		return $filter;
	}

	/**
	 * Builds the version 1 layout: every column of the current table except after_snapshot_id. The table name is fixed
	 * (see Schema::table()), and the statement has no input.
	 */
	private function create_version_one_table(): void {
		global $wpdb;

		$table = Schema::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test builds the old table layout; the table name is fixed.
		$wpdb->query(
			"CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  agent_label VARCHAR(191) NOT NULL DEFAULT '',
  ability VARCHAR(100) NOT NULL DEFAULT '',
  object_type VARCHAR(40) NOT NULL DEFAULT '',
  object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  input LONGTEXT NOT NULL,
  result VARCHAR(20) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  guardrail_notes TEXT NOT NULL,
  snapshot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  undoable TINYINT(1) NOT NULL DEFAULT 0,
  undone_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY user_created (user_id, created_at),
  KEY user_id_id (user_id, id)
) {$wpdb->get_charset_collate()};"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Inserts a row the way a version 1 write stored it: undoable, with a before-snapshot and no after-state column.
	 *
	 * @return int The row ID.
	 */
	private function insert_version_one_row(): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture written straight to the change-log table.
			Schema::table(),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => 5,
				'ability'     => 'steadhand/update-post',
				'result'      => 'ok',
				'snapshot_id' => 7,
				'undoable'    => 1,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Makes the ALTER that adds after_snapshot_id fail, the way a refused upgrade would. Counts the ALTERs it sees.
	 *
	 * @param int $alters Set by reference to the number of ALTER statements seen. Read it after the call.
	 * @return \Closure
	 */
	private function fail_the_after_snapshot_alter( int &$alters ): \Closure {
		$filter = static function ( string $query ) use ( &$alters ): string {
			if ( str_starts_with( $query, 'ALTER TABLE' ) && str_contains( $query, 'ADD COLUMN after_snapshot_id' ) ) {
				++$alters;
				return 'THIS IS NOT SQL';
			}

			return $query;
		};
		add_filter( 'query', $filter );

		return $filter;
	}

	/**
	 * Version 3 is the layout with the snapshots table, and the snapshots table has exactly the columns the code writes.
	 */
	public function test_version_three_has_the_snapshots_table_with_exactly_the_columns_the_code_writes(): void {
		global $wpdb;

		$this->assertSame( '3', Schema::DB_VERSION );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );
		$this->assertTrue( Schema::snapshots_ready() );

		$table = Schema::snapshots_table();
		// The table name is fixed (see Schema::snapshots_table()); SHOW COLUMNS cannot bind an identifier.
		$names = array_column( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ), 'Field' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads the table definition; the table name is fixed.
		sort( $names );
		$expected = Schema::SNAPSHOT_COLUMNS;
		sort( $expected );

		$this->assertSame( $expected, $names );
	}

	/**
	 * An install from version 2 adds the snapshots table, and leaves every change-log row as it was, undoable included when it
	 * has its after-state.
	 */
	public function test_an_upgrade_from_version_two_keeps_every_log_row_and_adds_the_snapshots_table(): void {
		global $wpdb;

		$this->let_ddl_reach_the_table();
		$log       = Schema::table();
		$snapshots = Schema::snapshots_table();

		try {
			Schema::uninstall();
			Schema::install();
			// Version 2 had no snapshots table. The fixed name is dropped to show that layout.
			$wpdb->query( "DROP TABLE IF EXISTS {$snapshots}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name; the test removes the table a version 2 install never had.
			$this->assertFalse( Schema::snapshots_ready() );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture written straight to the change-log table.
				$log,
				array(
					'created_at'        => gmdate( 'Y-m-d H:i:s' ),
					'user_id'           => 5,
					'ability'           => 'steadhand/update-post',
					'result'            => 'ok',
					'snapshot_id'       => 7,
					'after_snapshot_id' => 8,
					'undoable'          => 1,
				)
			);
			$id = (int) $wpdb->insert_id;

			update_option( Schema::OPTION, '2' );
			Schema::maybe_upgrade();

			$this->assertTrue( Schema::snapshots_ready() );
			$this->assertSame( '3', get_option( Schema::OPTION ) );
			$kept = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$log} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The table name is fixed.
			$this->assertIsArray( $kept );
			$this->assertSame( '7', (string) $kept['snapshot_id'] );
			$this->assertSame( '8', (string) $kept['after_snapshot_id'] );
			$this->assertSame( '1', (string) $kept['undoable'], 'A row with an after-state keeps its undoable flag through the upgrade.' );
		} finally {
			$this->restore_baseline();
		}
	}

	/**
	 * A snapshots table that cannot be created leaves the version unrecorded and sets the back-off, but the log still works:
	 * logging does not depend on the snapshots table.
	 */
	public function test_a_missing_snapshots_table_keeps_logging_and_leaves_the_version_unrecorded(): void {
		$this->let_ddl_reach_the_table();
		$filter = static function ( string $query ): string {
			return str_starts_with( $query, 'CREATE TABLE' ) && str_contains( $query, 'steadhand_snapshots' ) ? 'THIS IS NOT SQL' : $query;
		};

		try {
			Schema::uninstall();
			add_filter( 'query', $filter );

			ob_start();
			Schema::install();
			$output = (string) ob_get_clean();

			$this->assertSame( '', $output, 'No database error may be printed.' );
			$this->assertFalse( get_option( Schema::OPTION ), 'The version is recorded only when both tables are ready.' );
			$this->assertNotFalse( get_transient( Schema::RETRY_TRANSIENT ), 'A failed snapshots table sets the back-off.' );
			$this->assertTrue( Schema::ready(), 'The change log is ready.' );
			$this->assertFalse( Schema::snapshots_ready() );

			$user = self::factory()->user->create();
			$this->assertTrue( $this->log( $this->entry( $user ) ), 'Logging works without the snapshots table.' );
			$this->assertSame( 1, $this->count_rows( $user ) );
		} finally {
			remove_filter( 'query', $filter );
			$this->restore_baseline();
		}

		$this->assertTrue( Schema::snapshots_ready() );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );
		$this->assertFalse( get_transient( Schema::RETRY_TRANSIENT ), 'A successful install clears the back-off.' );
	}

	/**
	 * Uninstall drops the snapshots table with the log table.
	 */
	public function test_uninstall_drops_the_snapshots_table(): void {
		global $wpdb;

		$this->let_ddl_reach_the_table();

		try {
			Schema::uninstall();

			$this->assertFalse( Schema::snapshots_ready() );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Schema::snapshots_table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test checks the table is gone.
			$this->assertNull( $found, 'uninstall must drop the snapshots table.' );
		} finally {
			$this->restore_baseline();
		}

		$this->assertTrue( Schema::snapshots_ready() );
	}

	/**
	 * The expected column names, sorted. A table upgraded from an older layout gets a new column at its end, so the order
	 * of the columns depends on the history of the table, and only the set of names is compared.
	 *
	 * @return string[]
	 */
	private function expected_columns(): array {
		$columns = Schema::REQUIRED_COLUMNS;
		sort( $columns );

		return $columns;
	}

	/**
	 * The column names of the change-log table, sorted (see expected_columns()).
	 *
	 * @return string[]
	 */
	private function column_names(): array {
		global $wpdb;

		$table = Schema::table();
		// The table name is fixed (see Schema::table()); SHOW COLUMNS cannot bind an identifier.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads the table definition; the table name is fixed.

		$names = array_column( (array) $columns, 'Field' );
		sort( $names );

		return $names;
	}
}
