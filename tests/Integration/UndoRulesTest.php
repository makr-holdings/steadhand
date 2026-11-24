<?php
/**
 * UndoRules: the shared rules that decide what can be undone, checked directly, including the malformed saved states.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\PostReader;
use Steadhand\Abilities\UndoRules;
use Steadhand\Log\Entry;
use Steadhand\Log\Schema;
use Steadhand\Log\SnapshotStore;

/**
 * Covers the pure rules of UndoRules. The agent's undo and the administrator's undo both rely on these, so each rule has a
 * refusing case as well as the accepting one.
 */
final class UndoRulesTest extends LogTestCase {

	/**
	 * A change-log row that passes every structural rule. Tests change one column at a time.
	 *
	 * @return array<string, mixed>
	 */
	private function eligible_row(): array {
		return array(
			'user_id'           => 7,
			'ability'           => 'steadhand/update-post',
			'object_type'       => 'post',
			'object_id'         => 42,
			'result'            => Entry::RESULT_OK,
			'undoable'          => 1,
			'undone_by'         => 0,
			'snapshot_id'       => 11,
			'after_snapshot_id' => 12,
		);
	}

	/**
	 * A terms state, as the saved-state rules read it.
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param int[]  $term_ids The term IDs.
	 * @return array<string, mixed>
	 */
	private function terms_state( string $taxonomy, array $term_ids ): array {
		return array(
			'taxonomy' => $taxonomy,
			'term_ids' => $term_ids,
		);
	}

	public function test_a_row_that_passes_every_structural_rule_is_eligible(): void {
		$this->assertTrue( UndoRules::structurally_eligible( $this->eligible_row() ) );
		$this->assertTrue( UndoRules::structurally_eligible( array_merge( $this->eligible_row(), array( 'result' => Entry::RESULT_FIXED ) ) ) );
	}

	/**
	 * Rows that each break one structural rule.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function refusing_rows(): array {
		return array(
			'a create-post row'                  => array( array( 'ability' => 'steadhand/create-post' ) ),
			'an ability that is not undoable'    => array( array( 'ability' => 'steadhand/get-post' ) ),
			'an attachment'                      => array( array( 'object_type' => 'attachment' ) ),
			'no object'                          => array( array( 'object_id' => 0 ) ),
			'a blocked result'                   => array( array( 'result' => Entry::RESULT_BLOCKED ) ),
			'an error result'                    => array( array( 'result' => Entry::RESULT_ERROR ) ),
			'a row that is not undoable'         => array( array( 'undoable' => 0 ) ),
			'a row already undone'               => array( array( 'undone_by' => 99 ) ),
			'no before-snapshot'                 => array( array( 'snapshot_id' => 0 ) ),
			'no after-snapshot'                  => array( array( 'after_snapshot_id' => 0 ) ),
			'the same before and after snapshot' => array( array( 'after_snapshot_id' => 11 ) ),
		);
	}

	/**
	 * Each structural rule refuses its own case.
	 *
	 * @param array<string, mixed> $change A column set to a refusing value.
	 * @dataProvider refusing_rows
	 */
	public function test_each_structural_rule_refuses_its_own_case( array $change ): void {
		$this->assertFalse( UndoRules::structurally_eligible( array_merge( $this->eligible_row(), $change ) ) );
	}

	public function test_the_kind_is_read_from_the_ability(): void {
		$this->assertSame( UndoRules::KIND_TEXT, UndoRules::kind_of( array( 'ability' => 'steadhand/update-post' ) ) );
		$this->assertSame( UndoRules::KIND_TEXT, UndoRules::kind_of( array( 'ability' => 'steadhand/edit-blocks' ) ) );
		$this->assertSame( UndoRules::KIND_TERMS, UndoRules::kind_of( array( 'ability' => 'steadhand/set-post-terms' ) ) );
		$this->assertSame( UndoRules::KIND_FEATURED, UndoRules::kind_of( array( 'ability' => 'steadhand/set-featured-image' ) ) );
	}

	/**
	 * Saved states of each malformed shape, with the kind they are checked as.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function malformed_states(): array {
		return array(
			'terms: a missing key'               => array(
				UndoRules::KIND_TERMS,
				array( 'taxonomy' => 'post_tag' ),
			),
			'terms: an extra key'                => array(
				UndoRules::KIND_TERMS,
				$this->terms_state( 'post_tag', array( 1 ) ) + array( 'extra' => 1 ),
			),
			'terms: an empty taxonomy'           => array(
				UndoRules::KIND_TERMS,
				$this->terms_state( '', array( 1 ) ),
			),
			'terms: a taxonomy that is not text' => array(
				UndoRules::KIND_TERMS,
				array(
					'taxonomy' => 5,
					'term_ids' => array( 1 ),
				),
			),
			'terms: IDs that are not a list'     => array(
				UndoRules::KIND_TERMS,
				$this->terms_state( 'post_tag', array( 'a' => 1 ) ),
			),
			'terms: an ID that is a string'      => array(
				UndoRules::KIND_TERMS,
				$this->terms_state( 'post_tag', array( '1' ) ),
			),
			'terms: an ID of zero'               => array(
				UndoRules::KIND_TERMS,
				$this->terms_state( 'post_tag', array( 0 ) ),
			),
			'terms: a repeated ID'               => array(
				UndoRules::KIND_TERMS,
				$this->terms_state( 'post_tag', array( 3, 3 ) ),
			),
			'terms: more than the cap'           => array(
				UndoRules::KIND_TERMS,
				$this->terms_state( 'post_tag', range( 1, 101 ) ),
			),
			'featured: a missing key'            => array( UndoRules::KIND_FEATURED, array() ),
			'featured: an extra key'             => array(
				UndoRules::KIND_FEATURED,
				array(
					'featured_media' => 1,
					'extra'          => 1,
				),
			),
			'featured: a string'                 => array( UndoRules::KIND_FEATURED, array( 'featured_media' => '1' ) ),
			'featured: negative'                 => array( UndoRules::KIND_FEATURED, array( 'featured_media' => -1 ) ),
			'featured: above the ID limit'       => array( UndoRules::KIND_FEATURED, array( 'featured_media' => PostReader::MAX_ID + 1 ) ),
		);
	}

	/**
	 * A malformed saved state is never valid.
	 *
	 * @param string               $kind  The kind.
	 * @param array<string, mixed> $state The decoded state.
	 * @dataProvider malformed_states
	 */
	public function test_a_malformed_saved_state_is_not_valid( string $kind, array $state ): void {
		$this->assertFalse( UndoRules::valid_state( $kind, $state ) );
	}

	public function test_well_formed_saved_states_are_valid(): void {
		$this->assertTrue( UndoRules::valid_state( UndoRules::KIND_TERMS, $this->terms_state( 'post_tag', array( 1, 2 ) ) ) );
		$this->assertTrue( UndoRules::valid_state( UndoRules::KIND_TERMS, $this->terms_state( 'post_tag', array() ) ) );
		$this->assertTrue( UndoRules::valid_state( UndoRules::KIND_TERMS, $this->terms_state( 'category', range( 1, 100 ) ) ) );
		$this->assertTrue( UndoRules::valid_state( UndoRules::KIND_FEATURED, array( 'featured_media' => 0 ) ) );
		$this->assertTrue( UndoRules::valid_state( UndoRules::KIND_FEATURED, array( 'featured_media' => PostReader::MAX_ID ) ) );
	}

	public function test_the_IDs_are_sorted_ascending_as_integers(): void {
		$this->assertSame( array( 2, 10, 33 ), UndoRules::sorted( array( '33', 2, '10' ) ) );
		$this->assertSame( array(), UndoRules::sorted( array() ) );
	}

	/**
	 * A pair of saved states must name the same post, user and kind, differ, and hold valid terms.
	 */
	public function test_a_saved_state_pair_must_name_the_same_post_user_kind_and_differ(): void {
		global $wpdb;

		$table = Schema::snapshots_table();
		$store = static function ( string $kind, int $object_id, int $user, array $state ) use ( $wpdb, $table ): int {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture: a saved state written straight to its table.
				$table,
				array(
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
					'user_id'     => $user,
					'kind'        => $kind,
					'object_type' => 'post',
					'object_id'   => $object_id,
					'state'       => (string) wp_json_encode( $state ),
				)
			);

			return (int) $wpdb->insert_id;
		};

		$terms_row = array(
			'user_id'           => 7,
			'ability'           => 'steadhand/set-post-terms',
			'object_type'       => 'post',
			'object_id'         => 42,
			'result'            => Entry::RESULT_OK,
			'undoable'          => 1,
			'undone_by'         => 0,
			'snapshot_id'       => $store( SnapshotStore::KIND_POST_TERMS, 42, 7, $this->terms_state( 'post_tag', array( 1 ) ) ),
			'after_snapshot_id' => $store( SnapshotStore::KIND_POST_TERMS, 42, 7, $this->terms_state( 'post_tag', array( 2 ) ) ),
		);
		$this->assertIsArray( UndoRules::saved_states( $terms_row ), 'A valid pair must be accepted.' );

		$other_user = array_merge( $terms_row, array( 'user_id' => 8 ) );
		$this->assertNull( UndoRules::saved_states( $other_user ), 'A pair that belongs to another user is refused.' );

		$other_post = array_merge( $terms_row, array( 'object_id' => 43 ) );
		$this->assertNull( UndoRules::saved_states( $other_post ), 'A pair that names another post is refused.' );

		$other_taxonomy = array_merge(
			$terms_row,
			array(
				'after_snapshot_id' => $store( SnapshotStore::KIND_POST_TERMS, 42, 7, $this->terms_state( 'category', array( 2 ) ) ),
			)
		);
		$this->assertNull( UndoRules::saved_states( $other_taxonomy ), 'Two states in different taxonomies are refused.' );

		$same_set = array_merge(
			$terms_row,
			array(
				'after_snapshot_id' => $store( SnapshotStore::KIND_POST_TERMS, 42, 7, $this->terms_state( 'post_tag', array( 1 ) ) ),
			)
		);
		$this->assertNull( UndoRules::saved_states( $same_set ), 'Two states with the same terms are refused.' );

		$wrong_kind = array_merge(
			$terms_row,
			array(
				'after_snapshot_id' => $store( SnapshotStore::KIND_FEATURED_IMAGE, 42, 7, array( 'featured_media' => 2 ) ),
			)
		);
		$this->assertNull( UndoRules::saved_states( $wrong_kind ), 'A state of another kind is refused.' );
	}

	public function test_a_revision_is_usable_only_as_a_revision_of_that_post_that_is_not_an_autosave(): void {
		$post     = self::factory()->post->create();
		$revision = (int) self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post,
				'post_status' => 'inherit',
			)
		);
		$this->assertTrue( UndoRules::is_revision_of( get_post( $revision ), $post ) );
		$this->assertFalse( UndoRules::is_revision_of( get_post( $revision ), $post + 1 ), 'A revision of another post is refused.' );
		$this->assertFalse( UndoRules::is_revision_of( get_post( $post ), $post ), 'A post is not a revision.' );
		$this->assertFalse( UndoRules::is_revision_of( null, $post ) );
		$this->assertFalse( UndoRules::is_revision_of( false, $post ) );

		$autosave = (int) self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post,
				'post_status' => 'inherit',
				'post_name'   => $post . '-autosave-v1',
			)
		);
		$this->assertFalse( UndoRules::is_revision_of( get_post( $autosave ), $post ), 'An autosave is refused.' );
	}

	public function test_the_text_of_a_revision_is_its_title_content_and_excerpt(): void {
		$revision = new \WP_Post(
			(object) array(
				'ID'           => 1,
				'post_type'    => 'revision',
				'post_title'   => 'T',
				'post_content' => 'C',
				'post_excerpt' => 'E',
				'post_status'  => 'inherit',
			)
		);

		$this->assertSame(
			array(
				'post_title'   => 'T',
				'post_content' => 'C',
				'post_excerpt' => 'E',
			),
			UndoRules::text_of( $revision )
		);
	}
}
