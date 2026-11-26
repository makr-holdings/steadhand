<?php
/**
 * PHPUnit bootstrap: loads WordPress, the MCP Adapter and Steadhand.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

$steadhand_root = dirname( __DIR__ );

require_once $steadhand_root . '/vendor/autoload.php';

$steadhand_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $steadhand_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run the tests with `composer test` (inside wp-env).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once $steadhand_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $steadhand_root ): void {
		// The MCP Adapter is a required plugin; wp-env installs it next to Steadhand.
		require_once dirname( $steadhand_root ) . '/mcp-adapter/mcp-adapter.php';
		require_once $steadhand_root . '/steadhand.php';
	}
);

require $steadhand_tests_dir . '/includes/bootstrap.php';

// The test suite loads the plugin without activating it, so run the activation step once.
\Steadhand\Roles::install();
\Steadhand\Log\Schema::install();

// The WordPress test installer recreates the core tables on every run, but not Steadhand's own tables. A test that runs
// real DDL commits the rows it wrote before that DDL, and an interrupted run can leave committed rows as well. Those rows
// would reach this run and break its row counts, so both tables are emptied here, once per run. This runs outside any
// test transaction, so the deletes commit. The counts before and after are kept in $GLOBALS['steadhand_clean'] for
// BootstrapCleanTablesTest, which checks that the tables were empty. A NULL count means the query failed (for example
// because the table is missing), and the test reports that as a failure.
global $wpdb;
$steadhand_tables = array( \Steadhand\Log\Schema::table(), \Steadhand\Log\Schema::snapshots_table() );

$GLOBALS['steadhand_clean'] = array();
foreach ( $steadhand_tables as $steadhand_table ) {
	// The table names are fixed (see Schema::table() and Schema::snapshots_table()); nothing here is built from input.
	$steadhand_before = $wpdb->get_var( "SELECT COUNT(*) FROM {$steadhand_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; a test-suite cleanup, outside any transaction.
	$wpdb->query( "DELETE FROM {$steadhand_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; a test-suite cleanup, outside any transaction.
	$steadhand_after = $wpdb->get_var( "SELECT COUNT(*) FROM {$steadhand_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; a test-suite cleanup, outside any transaction.

	$GLOBALS['steadhand_clean'][ $steadhand_table ] = array(
		'before' => $steadhand_before,
		'after'  => $steadhand_after,
	);
}

// The prune marker (DbLogger::prune(), not autoloaded) is an option, not a table. A marker left by an earlier run would
// skip the prune for a day, so it is removed too. The literal is the option name the logger uses.
delete_option( 'steadhand_log_last_prune' );
