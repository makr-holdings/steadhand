<?php
/**
 * The change-log table: its columns, installation, upgrade and removal.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\Schema;

/**
 * Covers SPEC §7 (the change log) and the table lifecycle that activation, boot and uninstall drive.
 */
final class SchemaTest extends LogTestCase {

	private const COLUMNS = array(
		'id',
		'created_at',
		'user_id',
		'agent_label',
		'ability',
		'object_type',
		'object_id',
		'input',
		'result',
		'message',
		'guardrail_notes',
		'snapshot_id',
		'undoable',
		'undone_by',
	);

	public function test_the_table_exists_after_install_with_every_column(): void {
		Schema::install();

		$this->assertTrue( Schema::exists() );
		$this->assertSame( self::COLUMNS, $this->column_names() );
	}

	public function test_install_is_idempotent_and_records_the_version(): void {
		Schema::install();
		Schema::install();

		$this->assertTrue( Schema::exists() );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION ) );
		$this->assertSame( self::COLUMNS, $this->column_names() );
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
			Schema::install();
		}

		$this->assertTrue( Schema::exists(), 'The table must be back for the other tests.' );
		$this->assertSame( self::COLUMNS, $this->column_names() );
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
			$this->assertSame( 1, $creates, 'Install ran one CREATE.' );
			$this->assertFalse( Schema::exists() );
			$this->assertFalse( get_option( Schema::OPTION ), 'A failed create must not record the layout version.' );
			$this->assertNotFalse( get_transient( Schema::RETRY_TRANSIENT ), 'A failed create sets the back-off.' );

			ob_start();
			Schema::maybe_upgrade();
			$output = (string) ob_get_clean();

			$this->assertSame( '', $output );
			$this->assertSame( 1, $creates, 'maybe_upgrade must run no CREATE while the back-off is set.' );
		} finally {
			remove_filter( 'query', $filter );
			Schema::install();
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

			$this->assertSame( 1, $creates, 'install() tries even while the back-off is set.' );
		} finally {
			remove_filter( 'query', $filter );
			Schema::install();
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
			Schema::install();
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
			Schema::install();
		}

		$keys = array_values( array_unique( array_column( (array) $rows, 'Key_name' ) ) );
		sort( $keys );
		$this->assertSame( array( 'PRIMARY', 'created_at', 'user_created', 'user_id_id' ), $keys );
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
	 * The column names of the change-log table, in table order.
	 *
	 * @return string[]
	 */
	private function column_names(): array {
		global $wpdb;

		$table = Schema::table();
		// The table name is fixed (see Schema::table()); SHOW COLUMNS cannot bind an identifier.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads the table definition; the table name is fixed.

		return array_column( (array) $columns, 'Field' );
	}
}
