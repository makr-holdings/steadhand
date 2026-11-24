<?php
/**
 * What set-post-terms and set-featured-image record for undo: the saved states before and after a change, and when a change
 * is not undoable.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\SnapshotStore;

/**
 * Covers the snapshot() and snapshot_after() of the two abilities (SPEC §5, §7). A changing call is undoable with both states;
 * a call that changes nothing, a call whose before state cannot be saved, and a call whose before state no longer matches what
 * it read are not.
 */
final class SetTermsAndImageRecordingTest extends PostWriteTestCase {

	private const TERMS    = 'steadhand/set-post-terms';
	private const FEATURED = 'steadhand/set-featured-image';

	/** The warning for a change whose before state could not be saved. */
	private const BEFORE_UNSAVED = 'Steadhand could not save the state before this change, so it cannot be undone.';

	/** The warning for a change whose after state could not be saved. */
	private const AFTER_UNSAVED = 'Steadhand could not save the state after this change, so it cannot be undone.';

	protected function tearDown(): void {
		remove_all_filters( 'query' );
		remove_all_filters( 'wp_get_object_terms' );
		remove_all_filters( 'get_post_metadata' );
		parent::tearDown();
	}

	private function tag( string $name ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => $name,
			)
		);
	}

	private function image(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
	}

	/**
	 * Calls set-post-terms as the current user, and returns the output.
	 *
	 * @param int    $post  Post ID.
	 * @param int[]  $terms Term IDs.
	 * @param string $mode Mode.
	 */
	private function set_terms( int $post, array $terms, string $mode = 'replace' ): mixed {
		return $this->execute_ability(
			self::TERMS,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => $terms,
				'mode'     => $mode,
			)
		);
	}

	/**
	 * Calls set-featured-image as the current user, and returns the output.
	 *
	 * @param int $post     Post ID.
	 * @param int $media_id Attachment ID, or 0.
	 */
	private function set_image( int $post, int $media_id ): mixed {
		return $this->execute_ability(
			self::FEATURED,
			array(
				'id'       => $post,
				'media_id' => $media_id,
			)
		);
	}

	/**
	 * The saved state a snapshot row holds.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>
	 */
	private function state_of( int $id ): array {
		$row = SnapshotStore::find( $id );
		$this->assertIsArray( $row, "Snapshot $id must exist." );

		return $row['state'];
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

	public function test_a_terms_change_is_undoable_and_stores_the_before_and_after_sets(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$a     = $this->tag( 'Alpha' );
		$b     = $this->tag( 'Beta' );
		wp_set_object_terms( $post, array( $b ), 'post_tag', false );

		$result = $this->set_terms( $post, array( $a ) );

		$this->assertTrue( $result['changed'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$this->assertGreaterThan( 0, (int) $row['snapshot_id'] );
		$this->assertGreaterThan( 0, (int) $row['after_snapshot_id'] );
		$this->assertNotSame( $row['snapshot_id'], $row['after_snapshot_id'] );
		$this->assertSame(
			array(
				'taxonomy' => 'post_tag',
				'term_ids' => array( $b ),
			),
			$this->state_of( (int) $row['snapshot_id'] )
		);
		$this->assertSame(
			array(
				'taxonomy' => 'post_tag',
				'term_ids' => array( $a ),
			),
			$this->state_of( (int) $row['after_snapshot_id'] )
		);
		$this->assertSame( array(), $result['warnings'], 'A stored change carries no undo warning.' );
	}

	public function test_add_and_remove_record_the_sets_as_they_were_and_are(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$a     = $this->tag( 'Alpha' );
		$b     = $this->tag( 'Beta' );
		wp_set_object_terms( $post, array( $a ), 'post_tag', false );

		$this->set_terms( $post, array( $b ), 'add' );
		$row = $this->latest_row( $agent );
		$this->assertSame( array( $a ), $this->state_of( (int) $row['snapshot_id'] )['term_ids'] );
		$this->assertSame( array( min( $a, $b ), max( $a, $b ) ), $this->state_of( (int) $row['after_snapshot_id'] )['term_ids'] );

		$this->set_terms( $post, array( $a ), 'remove' );
		$row = $this->latest_row( $agent );
		$this->assertSame( array( min( $a, $b ), max( $a, $b ) ), $this->state_of( (int) $row['snapshot_id'] )['term_ids'] );
		$this->assertSame( array( $b ), $this->state_of( (int) $row['after_snapshot_id'] )['term_ids'] );
	}

	public function test_a_terms_call_that_changes_nothing_is_not_undoable(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$a     = $this->tag( 'Alpha' );
		wp_set_object_terms( $post, array( $a ), 'post_tag', false );

		$result = $this->set_terms( $post, array( $a ) );

		$this->assertFalse( $result['changed'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['undoable'] );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_a_terms_change_whose_before_state_cannot_be_saved_succeeds_and_is_not_undoable(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$a     = $this->tag( 'Alpha' );
		$this->make_snapshots_look_missing();

		$result = $this->set_terms( $post, array( $a ) );
		remove_all_filters( 'query' );

		$this->assertTrue( $result['changed'], 'The write itself is not refused.' );
		$this->assertSame( array( $a ), array_map( 'intval', wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) ) );
		$this->assertContains( self::BEFORE_UNSAVED, $result['warnings'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['undoable'] );
		$this->assertSame( 0, (int) $row['snapshot_id'] );
	}

	public function test_terms_changed_between_the_snapshot_and_the_write_are_not_undoable(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$first = $this->tag( 'First' );
		$extra = $this->tag( 'Extra' );
		$mine  = $this->tag( 'Mine' );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );

		// The first read of the terms is the snapshot's. The other term is added right after it, so the call reads a different set.
		$fired = false;
		add_filter(
			'wp_get_object_terms',
			static function ( $terms ) use ( &$fired, $post, $extra ) {
				if ( ! $fired ) {
					$fired = true;
					wp_set_object_terms( $post, array( $extra ), 'post_tag', true );
				}
				return $terms;
			}
		);

		$result = $this->set_terms( $post, array( $mine ) );
		remove_all_filters( 'wp_get_object_terms' );

		$this->assertTrue( $result['changed'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['undoable'], 'The before state no longer matches what the call read.' );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
		$this->assertContains( self::AFTER_UNSAVED, $result['warnings'] );
	}

	public function test_an_image_set_is_undoable_and_stores_none_as_zero(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$image = $this->image();

		$result = $this->set_image( $post, $image );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( array(), $result['warnings'], 'A stored change carries no undo warning.' );
		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$this->assertSame( array( 'featured_media' => 0 ), $this->state_of( (int) $row['snapshot_id'] ) );
		$this->assertSame( array( 'featured_media' => $image ), $this->state_of( (int) $row['after_snapshot_id'] ) );
	}

	public function test_a_removal_stores_the_image_as_the_before_state_and_zero_as_the_after(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$image = $this->image();
		set_post_thumbnail( $post, $image );

		$this->set_image( $post, 0 );

		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$this->assertSame( array( 'featured_media' => $image ), $this->state_of( (int) $row['snapshot_id'] ) );
		$this->assertSame( array( 'featured_media' => 0 ), $this->state_of( (int) $row['after_snapshot_id'] ) );
	}

	public function test_an_image_call_that_changes_nothing_is_not_undoable(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$image = $this->image();
		set_post_thumbnail( $post, $image );

		$result = $this->set_image( $post, $image );

		$this->assertFalse( $result['changed'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['undoable'] );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
	}

	public function test_an_image_change_whose_before_state_cannot_be_saved_succeeds_and_is_not_undoable(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$image = $this->image();
		$this->make_snapshots_look_missing();

		$result = $this->set_image( $post, $image );
		remove_all_filters( 'query' );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $post ) );
		$this->assertContains( self::BEFORE_UNSAVED, $result['warnings'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	public function test_an_image_changed_between_the_snapshot_and_the_write_is_not_undoable(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$other = $this->image();
		$image = $this->image();

		// The first read of the thumbnail is the snapshot's. The other image is set right after it.
		$fired = false;
		add_filter(
			'get_post_metadata',
			static function ( $value, $object_id, $meta_key, $single ) use ( &$fired, $post, $other ) {
				if ( $fired || (int) $object_id !== $post || '_thumbnail_id' !== $meta_key ) {
					return $value;
				}

				$fired  = true;
				$stored = get_post_meta( $post, $meta_key, true );
				update_post_meta( $post, '_thumbnail_id', $other );

				return $single ? $stored : array( $stored );
			},
			10,
			4
		);

		$result = $this->set_image( $post, $image );
		remove_all_filters( 'get_post_metadata' );

		$this->assertTrue( $result['changed'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['undoable'] );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
		$this->assertContains( self::AFTER_UNSAVED, $result['warnings'] );
	}

	/** The warning for a change to a post with more terms in the taxonomy than one undo can put back. */
	private const TOO_MANY = 'Steadhand cannot undo this change: the post had more than 100 terms in this taxonomy before it, and an undo can put back at most 100.';

	public function test_a_change_of_a_post_with_more_than_100_terms_before_is_not_undoable_and_says_so(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$ids   = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$ids[] = (int) self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => "Bulk $i",
				)
			);
		}
		wp_set_object_terms( $post, $ids, 'post_tag', false );

		// A remove leaves 100 terms, which a direct call may send. The state before, 101 terms, could not be restored by an undo.
		$result = $this->set_terms( $post, array( $ids[0] ), 'remove' );

		$this->assertTrue( $result['changed'] );
		$this->assertContains( self::TOO_MANY, $result['warnings'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['undoable'] );
		$this->assertSame( 0, (int) $row['snapshot_id'] );
		$this->assertSame( 0, (int) $row['after_snapshot_id'] );
	}
}
