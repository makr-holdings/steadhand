<?php
/**
 * The change-log listener gets the same PHP types whether the row came from log() or from complete().
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;

/**
 * Integration tests for the types of rows passed to the change-log listener.
 */
final class LogAnnounceTypesTest extends LogTestCase {

	/**
	 * Every integer column of a stored row.
	 */
	private const INT_COLUMNS = array( 'id', 'user_id', 'object_id', 'snapshot_id', 'undoable', 'undone_by' );

	public function test_a_row_completed_with_complete_reaches_listeners_with_integer_columns_as_integers(): void {
		$user = self::factory()->user->create( array( 'role' => \Steadhand\Roles::ROLE ) );
		$seen = array();
		add_action(
			'steadhand_log_entry_stored',
			static function ( $id, $row ) use ( &$seen ): void {
				unset( $id );
				$seen[] = $row;
			},
			10,
			2
		);
		$logger = new DbLogger();
		$id     = $logger->begin( new Entry( $user, '', 'steadhand/update-post', Entry::RESULT_ERROR, '', array(), 'post', 5 ) );
		$this->assertGreaterThan( 0, $id );

		$this->assertTrue( $logger->complete( $id, new Entry( $user, '', 'steadhand/update-post', Entry::RESULT_OK, '', array(), 'post', 5, '', 7, true ) ) );

		$this->assertCount( 1, $seen );
		foreach ( self::INT_COLUMNS as $column ) {
			$this->assertIsInt( $seen[0][ $column ], "$column must be an int from complete()." );
		}
		$this->assertSame( 7, $seen[0]['snapshot_id'] );
		$this->assertSame( 1, $seen[0]['undoable'] );
	}

	public function test_a_row_written_with_log_reaches_listeners_with_the_same_types(): void {
		$user = self::factory()->user->create( array( 'role' => \Steadhand\Roles::ROLE ) );
		$seen = array();
		add_action(
			'steadhand_log_entry_stored',
			static function ( $id, $row ) use ( &$seen ): void {
				unset( $id );
				$seen[] = $row;
			},
			10,
			2
		);

		( new DbLogger() )->log( new Entry( $user, '', 'steadhand/update-post', Entry::RESULT_OK, '', array(), 'post', 5, '', 7, true ) );

		$this->assertCount( 1, $seen );
		foreach ( self::INT_COLUMNS as $column ) {
			$this->assertIsInt( $seen[0][ $column ], "$column must be an int from log()." );
		}
	}
}
