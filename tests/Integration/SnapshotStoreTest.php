<?php
/**
 * The snapshots table: saving and reading one state, its refusals, and its pruning.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\DbLogger;
use Steadhand\Log\Schema;
use Steadhand\Log\SnapshotStore;

/**
 * Covers SPEC §7 for the snapshots table: a save is made only inside a write, an unknown kind or an unusable object saves
 * nothing, a table that is not ready gives 0, find() never throws and reads only a JSON object, and the prune job removes
 * only the rows past retention.
 */
final class SnapshotStoreTest extends PostWriteTestCase {

	/** An ID no snapshot row has. */
	private const MISSING_ID = 987654321;

	protected function tearDown(): void {
		remove_all_filters( 'query' );
		parent::tearDown();
	}

	/**
	 * A small terms state.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		return array(
			'taxonomy' => 'post_tag',
			'term_ids' => array( 3, 7 ),
		);
	}

	/**
	 * Saves one state inside a real write, as the current user, and returns the row ID.
	 *
	 * @param string               $kind  Kind.
	 * @param string               $type  Object type.
	 * @param int                  $id    Object ID.
	 * @param array<string, mixed> $state State.
	 */
	private function save_in_write( string $kind, string $type, int $id, array $state ): int {
		return (int) $this->in_write( static fn(): int => SnapshotStore::save( $kind, $type, $id, $state ) );
	}

	/**
	 * The number of snapshot rows that name one object.
	 *
	 * @param int $object_id Object ID.
	 */
	private function rows_for_object( int $object_id ): int {
		global $wpdb;

		$table = Schema::snapshots_table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test counts the snapshot rows directly.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_id = %d", $object_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is fixed.
		);
	}

	/**
	 * Inserts a snapshot row as another write would leave it, with no checks.
	 *
	 * @param array<string, mixed> $fields Columns.
	 */
	private function insert_raw( array $fields ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture written straight to the snapshots table.
			Schema::snapshots_table(),
			array_merge(
				array(
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
					'user_id'     => 1,
					'kind'        => SnapshotStore::KIND_POST_TERMS,
					'object_type' => 'post',
					'object_id'   => 1,
					'state'       => '{}',
				),
				$fields
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Makes the snapshots table look missing to the existence check.
	 */
	private function make_snapshots_look_missing(): void {
		add_filter(
			'query',
			static function ( string $query ): string {
				return str_starts_with( $query, 'SHOW TABLES LIKE' ) && str_contains( $query, 'snapshots' )
					? "SHOW TABLES LIKE 'steadhand_no_such_table'"
					: $query;
			}
		);
	}

	public function test_save_outside_a_write_throws(): void {
		$this->expectException( \LogicException::class );

		SnapshotStore::save( SnapshotStore::KIND_POST_TERMS, 'post', 1, $this->state() );
	}

	public function test_a_saved_state_is_found_with_its_kind_object_and_user(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post();

		$id  = $this->save_in_write( SnapshotStore::KIND_POST_TERMS, 'post', $post, $this->state() );
		$row = SnapshotStore::find( $id );

		$this->assertGreaterThan( 0, $id );
		$this->assertIsArray( $row );
		$this->assertSame( $id, $row['id'] );
		$this->assertSame( SnapshotStore::KIND_POST_TERMS, $row['kind'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( $post, $row['object_id'] );
		$this->assertSame( $agent, $row['user_id'], 'The row names the user who was saving.' );
		$this->assertSame( $this->state(), $row['state'] );
	}

	public function test_the_featured_image_kind_is_saved_and_found(): void {
		$this->act_as_agent();
		$post = $this->admin_post();

		$id = $this->save_in_write( SnapshotStore::KIND_FEATURED_IMAGE, 'post', $post, array( 'featured_media' => 0 ) );

		$this->assertSame( array( 'featured_media' => 0 ), SnapshotStore::find( $id )['state'] );
	}

	public function test_an_unknown_kind_saves_nothing_and_returns_zero(): void {
		$this->act_as_agent();
		$post = $this->admin_post();

		$this->assertSame( 0, $this->save_in_write( 'not_a_kind', 'post', $post, $this->state() ) );
		$this->assertSame( 0, $this->rows_for_object( $post ) );
	}

	public function test_an_object_that_cannot_be_named_saves_nothing(): void {
		$this->act_as_agent();
		$post = $this->admin_post();

		$this->assertSame( 0, $this->save_in_write( SnapshotStore::KIND_POST_TERMS, 'post', 0, $this->state() ) );
		$this->assertSame( 0, $this->save_in_write( SnapshotStore::KIND_POST_TERMS, '', $post, $this->state() ) );
		$this->assertSame( 0, $this->save_in_write( SnapshotStore::KIND_POST_TERMS, str_repeat( 'p', 33 ), $post, $this->state() ) );
		$this->assertSame( 0, $this->rows_for_object( $post ) );
	}

	public function test_a_state_over_the_size_cap_saves_nothing(): void {
		$this->act_as_agent();
		$post = $this->admin_post();

		$big = array(
			'taxonomy' => str_repeat( 'a', SnapshotStore::MAX_STATE_BYTES ),
			'term_ids' => array(),
		);

		$this->assertSame( 0, $this->save_in_write( SnapshotStore::KIND_POST_TERMS, 'post', $post, $big ) );
		$this->assertSame( 0, $this->rows_for_object( $post ) );
	}

	public function test_a_table_that_is_not_ready_gives_zero_and_writes_nothing(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		$this->make_snapshots_look_missing();

		$this->assertSame( 0, $this->save_in_write( SnapshotStore::KIND_POST_TERMS, 'post', $post, $this->state() ) );
		$this->assertNull( SnapshotStore::find( 1 ), 'find() reads nothing when the table is not ready.' );
		remove_all_filters( 'query' );

		$this->assertSame( 0, $this->rows_for_object( $post ) );
	}

	public function test_find_of_a_missing_row_or_an_invalid_id_is_null(): void {
		$this->assertNull( SnapshotStore::find( self::MISSING_ID ) );
		$this->assertNull( SnapshotStore::find( 0 ) );
	}

	public function test_find_of_a_row_whose_state_is_not_a_json_object_is_null(): void {
		$bad    = $this->insert_raw( array( 'state' => '{"taxonomy": ' ) );
		$scalar = $this->insert_raw( array( 'state' => '5' ) );

		$this->assertNull( SnapshotStore::find( $bad ), 'Malformed JSON is not a state.' );
		$this->assertNull( SnapshotStore::find( $scalar ), 'A JSON scalar is not a state.' );
	}

	public function test_the_prune_job_deletes_only_rows_past_retention(): void {
		$old   = $this->insert_raw(
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ),
				'object_id'  => 4242,
			)
		);
		$young = $this->insert_raw(
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'object_id'  => 4242,
			)
		);
		delete_option( 'steadhand_log_last_prune' );

		$user = self::factory()->user->create();
		$this->assertTrue( ( new DbLogger() )->log( $this->entry( $user ) ) );

		$this->assertNull( SnapshotStore::find( $old ), 'A row past the log retention is pruned with the log.' );
		$this->assertIsArray( SnapshotStore::find( $young ), 'A row younger than the retention is kept.' );
	}
}
