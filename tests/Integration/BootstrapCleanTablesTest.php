<?php
/**
 * Checks the clean-up that tests/bootstrap.php runs once per test run: both Steadhand tables were empty before any test.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\Schema;

/**
 * The bootstrap deletes every row of the change-log and snapshots tables, and records the counts it read before and after
 * the delete in $GLOBALS['steadhand_clean'] (tests/bootstrap.php). This test reads that record, not the live table, so a test
 * that runs earlier in the run cannot change what is checked. A failed count is recorded as NULL and fails the test, so a
 * missing table cannot pass as an empty one.
 */
final class BootstrapCleanTablesTest extends TestCase {

	public function test_the_bootstrap_recorded_both_tables_as_empty_after_it_cleaned_them(): void {
		$this->assertArrayHasKey( 'steadhand_clean', $GLOBALS, 'The bootstrap must record its clean-up.' );

		foreach ( array( Schema::table(), Schema::snapshots_table() ) as $table ) {
			$this->assertArrayHasKey( $table, $GLOBALS['steadhand_clean'], "The bootstrap did not clean $table." );
			$this->assertSame( '0', $GLOBALS['steadhand_clean'][ $table ]['after'], "$table still held rows after the bootstrap cleaned it." );
		}
	}
}
