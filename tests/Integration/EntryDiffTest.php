<?php
/**
 * EntryDiff: the before and after data of one change-log entry, and the refusals that keep it from showing what it should not.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\EntryDiff;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\Schema;
use Steadhand\Log\SnapshotStore;

/**
 * The diff is built from saved states only. These tests use real changes for the three kinds, and alter the saved rows for the
 * refusals. The viewer is an administrator, who may edit the posts, except where a test says otherwise.
 */
final class EntryDiffTest extends PostWriteTestCase {

	private const UPDATE   = 'steadhand/update-post';
	private const EDIT     = 'steadhand/edit-blocks';
	private const TERMS    = 'steadhand/set-post-terms';
	private const FEATURED = 'steadhand/set-featured-image';

	/**
	 * The filter that denies edit_post for one post, removed in tearDown.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $deny = null;

	protected function tearDown(): void {
		if ( null !== $this->deny ) {
			remove_filter( 'map_meta_cap', $this->deny, 10 );
			$this->deny = null;
		}
		parent::tearDown();
	}

	private static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Makes an update-post change as the agent and returns the entry, as the log stores it.
	 *
	 * @return array<string, mixed>
	 */
	private function update_entry(): array {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Before title',
				'post_content' => self::paragraph( 'Before' ),
				'post_excerpt' => 'Before excerpt',
			)
		);
		$this->execute_ability(
			self::UPDATE,
			array(
				'id'      => $post,
				'title'   => 'After title',
				'content' => self::paragraph( 'After' ),
			)
		);

		return $this->entry_of( $agent );
	}

	/**
	 * The most recent entry of one user, read back as DbLogger::find_any() returns it.
	 *
	 * @param int $user_id The agent.
	 * @return array<string, mixed>
	 */
	private function entry_of( int $user_id ): array {
		$row = $this->latest_row( $user_id );
		$any = ( new DbLogger() )->find_any( (int) $row['id'] );
		$this->assertIsArray( $any );

		return $any;
	}

	/**
	 * Stores an entry with the given fields, and reads it back.
	 *
	 * @param array<string, mixed> $fields Columns to set.
	 * @return array<string, mixed>
	 */
	private function stored_entry( array $fields ): array {
		$id  = $this->insert_row( $fields );
		$any = ( new DbLogger() )->find_any( $id );
		$this->assertIsArray( $any );

		return $any;
	}

	/**
	 * A text entry that names two revisions, as update-post would store it.
	 *
	 * @param int $post   The post.
	 * @param int $before The before revision.
	 * @param int $after  The after revision.
	 * @return array<string, mixed>
	 */
	private function text_entry( int $post, int $before, int $after ): array {
		return $this->stored_entry(
			array(
				'user_id'           => $this->admin_id(),
				'ability'           => self::UPDATE,
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => Entry::RESULT_OK,
				'snapshot_id'       => $before,
				'after_snapshot_id' => $after,
				'undoable'          => 1,
			)
		);
	}

	/**
	 * Inserts a saved state directly, as a write would have saved it.
	 *
	 * @param string               $kind        Kind.
	 * @param string               $object_type Object type.
	 * @param int                  $object_id   Object ID.
	 * @param array<string, mixed> $state       State, as JSON.
	 * @return int The row ID.
	 */
	private function snapshot( string $kind, string $object_type, int $object_id, array $state ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture: a saved state as a write would leave it.
			Schema::snapshots_table(),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => $this->admin_id(),
				'kind'        => $kind,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'state'       => (string) wp_json_encode( $state ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Changes a saved state row directly.
	 *
	 * @param int                  $id     Row ID.
	 * @param array<string, mixed> $fields Columns to set.
	 */
	private function alter_snapshot( int $id, array $fields ): void {
		global $wpdb;

		$wpdb->update( Schema::snapshots_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the saved state is altered to test a refusal.
	}

	private function view_as_admin(): void {
		wp_set_current_user( $this->admin_id() );
	}

	/**
	 * Creates a post tag with the given name, and returns its ID.
	 *
	 * @param string $name Term name.
	 */
	private function tag( string $name ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => $name,
			)
		);
	}

	public function test_a_text_change_shows_only_the_fields_that_changed(): void {
		$entry = $this->update_entry();

		$this->view_as_admin();
		$diff = EntryDiff::for_entry( $entry );

		$this->assertSame( EntryDiff::STATUS_OK, $diff['status'] );
		$this->assertSame( EntryDiff::KIND_TEXT, $diff['kind'] );
		$this->assertSame( array( 'Title', 'Content' ), array_column( $diff['sections'], 'label' ) );
		$this->assertSame( 'Before title', $diff['sections'][0]['before'] );
		$this->assertSame( 'After title', $diff['sections'][0]['after'] );
		$this->assertStringContainsString( 'After', $diff['sections'][1]['after'] );
	}

	public function test_an_edit_blocks_change_is_shown_as_a_content_diff(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ),
			)
		);
		$this->execute_ability(
			self::EDIT,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'Changed' ),
					),
				),
			)
		);

		$this->view_as_admin();
		$diff = EntryDiff::for_entry( $this->entry_of( $agent ) );

		$this->assertSame( EntryDiff::STATUS_OK, $diff['status'] );
		$this->assertSame( EntryDiff::KIND_TEXT, $diff['kind'] );
		$this->assertSame( array( 'Content' ), array_column( $diff['sections'], 'label' ) );
		$this->assertStringContainsString( 'Two', $diff['sections'][0]['before'] );
		$this->assertStringContainsString( 'Changed', $diff['sections'][0]['after'] );
	}

	public function test_a_terms_change_names_the_terms_removed_and_added(): void {
		$alpha = $this->tag( 'Alpha' );
		$beta  = $this->tag( 'Beta' );
		$gamma = $this->tag( 'Gamma' );

		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $alpha, $beta ), 'post_tag', false );
		$this->execute_ability(
			self::TERMS,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $beta, $gamma ),
				'mode'     => 'replace',
			)
		);

		$this->view_as_admin();
		$diff = EntryDiff::for_entry( $this->entry_of( $agent ) );

		$this->assertSame( EntryDiff::STATUS_OK, $diff['status'] );
		$this->assertSame( EntryDiff::KIND_TERMS, $diff['kind'] );
		$section = $diff['sections'][0];
		$this->assertSame( 'post_tag', $section['taxonomy'] );
		$this->assertSame(
			array(
				array(
					'id'   => $alpha,
					'name' => 'Alpha',
				),
			),
			$section['removed']
		);
		$this->assertSame(
			array(
				array(
					'id'   => $gamma,
					'name' => 'Gamma',
				),
			),
			$section['added']
		);
	}

	public function test_a_featured_image_change_names_the_images_before_and_after(): void {
		$first  = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
		$second = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );

		$agent = $this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		set_post_thumbnail( $post, $first );
		$this->execute_ability(
			self::FEATURED,
			array(
				'id'       => $post,
				'media_id' => $second,
			)
		);

		$this->view_as_admin();
		$diff = EntryDiff::for_entry( $this->entry_of( $agent ) );

		$this->assertSame( EntryDiff::STATUS_OK, $diff['status'] );
		$this->assertSame( EntryDiff::KIND_FEATURED_IMAGE, $diff['kind'] );
		$section = $diff['sections'][0];
		$this->assertSame( $first, $section['before']['id'] );
		$this->assertSame( get_post( $first )->post_title, $section['before']['title'] );
		$this->assertSame( $second, $section['after']['id'] );
		$this->assertSame( get_post( $second )->post_title, $section['after']['title'] );
	}

	public function test_a_create_post_entry_and_a_blocked_entry_have_no_diff(): void {
		$post = $this->admin_post();

		$created = $this->stored_entry(
			array(
				'ability'     => 'steadhand/create-post',
				'object_type' => 'post',
				'object_id'   => $post,
				'result'      => Entry::RESULT_OK,
			)
		);
		$blocked = $this->stored_entry(
			array(
				'ability'           => self::UPDATE,
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => Entry::RESULT_BLOCKED,
				'snapshot_id'       => 5,
				'after_snapshot_id' => 6,
			)
		);

		$this->assertSame( EntryDiff::STATUS_NONE, EntryDiff::for_entry( $created )['status'] );
		$this->assertSame( '', EntryDiff::for_entry( $created )['kind'] );
		$this->assertSame( EntryDiff::STATUS_NONE, EntryDiff::for_entry( $blocked )['status'] );
	}

	public function test_an_entry_without_both_states_or_with_equal_states_has_no_diff(): void {
		$post = $this->admin_post();

		$only_before = $this->stored_entry(
			array(
				'ability'           => self::UPDATE,
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => Entry::RESULT_OK,
				'snapshot_id'       => 5,
				'after_snapshot_id' => 0,
			)
		);
		$same        = $this->stored_entry(
			array(
				'ability'           => self::UPDATE,
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => Entry::RESULT_OK,
				'snapshot_id'       => 5,
				'after_snapshot_id' => 5,
			)
		);

		$this->assertSame( EntryDiff::STATUS_NONE, EntryDiff::for_entry( $only_before )['status'] );
		$this->assertSame( EntryDiff::STATUS_NONE, EntryDiff::for_entry( $same )['status'] );
	}

	public function test_a_deleted_revision_is_unavailable(): void {
		$entry = $this->update_entry();

		wp_delete_post( (int) $entry['after_snapshot_id'], true );

		$this->view_as_admin();
		$this->assertSame( EntryDiff::STATUS_UNAVAILABLE, EntryDiff::for_entry( $entry )['status'] );
	}

	public function test_a_revision_of_another_post_is_unavailable(): void {
		$entry = $this->update_entry();
		$other = $this->admin_post();
		$rev   = (int) self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $other,
				'post_status' => 'inherit',
			)
		);

		$this->view_as_admin();
		$diff = EntryDiff::for_entry( $this->text_entry( (int) $entry['object_id'], $rev, (int) $entry['after_snapshot_id'] ) );

		$this->assertSame( EntryDiff::STATUS_UNAVAILABLE, $diff['status'] );
		$this->assertSame( array(), $diff['sections'] );
	}

	public function test_an_autosave_is_unavailable(): void {
		$entry    = $this->update_entry();
		$post     = (int) $entry['object_id'];
		$autosave = (int) self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post,
				'post_status' => 'inherit',
				'post_name'   => $post . '-autosave-v1',
			)
		);

		$this->view_as_admin();
		$this->assertSame( EntryDiff::STATUS_UNAVAILABLE, EntryDiff::for_entry( $this->text_entry( $post, $autosave, (int) $entry['after_snapshot_id'] ) )['status'] );
	}

	/**
	 * A real terms change, from one tag to another, as the agent. Returns the entry.
	 *
	 * @return array<string, mixed>
	 */
	private function terms_entry(): array {
		$alpha = $this->tag( 'Alpha' );
		$beta  = $this->tag( 'Beta' );

		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $alpha ), 'post_tag', false );
		$this->execute_ability(
			self::TERMS,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $beta ),
				'mode'     => 'replace',
			)
		);

		return $this->entry_of( $agent );
	}

	public function test_a_saved_state_of_another_kind_is_unavailable(): void {
		$entry = $this->terms_entry();

		$this->view_as_admin();
		$this->alter_snapshot( (int) $entry['snapshot_id'], array( 'kind' => SnapshotStore::KIND_FEATURED_IMAGE ) );

		$this->assertSame( EntryDiff::STATUS_UNAVAILABLE, EntryDiff::for_entry( $entry )['status'] );
	}

	public function test_a_saved_state_of_another_object_is_unavailable(): void {
		$entry = $this->terms_entry();

		$this->view_as_admin();
		$this->alter_snapshot( (int) $entry['after_snapshot_id'], array( 'object_id' => $this->admin_post() ) );

		$this->assertSame( EntryDiff::STATUS_UNAVAILABLE, EntryDiff::for_entry( $entry )['status'] );
	}

	public function test_a_saved_state_with_a_malformed_shape_is_unavailable(): void {
		$post   = $this->admin_post();
		$before = $this->snapshot(
			SnapshotStore::KIND_POST_TERMS,
			'post',
			$post,
			array(
				'taxonomy' => 'post_tag',
				'term_ids' => array( 1 ),
			)
		);
		$after  = $this->snapshot(
			SnapshotStore::KIND_POST_TERMS,
			'post',
			$post,
			array(
				'taxonomy' => 'post_tag',
				'term_ids' => 'x',
			)
		);

		$entry = $this->stored_entry(
			array(
				'ability'           => self::TERMS,
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => Entry::RESULT_OK,
				'snapshot_id'       => $before,
				'after_snapshot_id' => $after,
			)
		);

		$this->view_as_admin();
		$this->assertSame( EntryDiff::STATUS_UNAVAILABLE, EntryDiff::for_entry( $entry )['status'] );
	}

	public function test_a_viewer_who_may_not_edit_the_post_gets_no_content(): void {
		$entry = $this->update_entry();
		$post  = (int) $entry['object_id'];

		$this->deny = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $post ): array {
			unset( $user_id );
			return ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $post ) ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $this->deny, 10, 4 );

		$this->view_as_admin();
		$diff = EntryDiff::for_entry( $entry );

		$this->assertSame( EntryDiff::STATUS_FORBIDDEN, $diff['status'] );
		$this->assertSame( array(), $diff['sections'] );
		$this->assertStringNotContainsString( 'After title', (string) wp_json_encode( $diff ) );
	}

	public function test_a_featured_image_that_is_not_an_attachment_shows_no_title(): void {
		$hidden = $this->admin_post(
			array(
				'post_status' => 'private',
				'post_title'  => 'Private post title XYZ',
			)
		);
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$before = $this->snapshot( SnapshotStore::KIND_FEATURED_IMAGE, 'post', $post, array( 'featured_media' => $hidden ) );
		$after  = $this->snapshot( SnapshotStore::KIND_FEATURED_IMAGE, 'post', $post, array( 'featured_media' => 0 ) );
		$entry  = $this->stored_entry(
			array(
				'user_id'           => $this->admin_id(),
				'ability'           => self::FEATURED,
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => Entry::RESULT_OK,
				'snapshot_id'       => $before,
				'after_snapshot_id' => $after,
			)
		);

		$this->view_as_admin();
		$diff = EntryDiff::for_entry( $entry );

		$this->assertSame( EntryDiff::STATUS_OK, $diff['status'] );
		$this->assertSame( $hidden, $diff['sections'][0]['before']['id'] );
		$this->assertNull( $diff['sections'][0]['before']['title'], 'A post that is not an attachment must not supply a title.' );
		$this->assertStringNotContainsString( 'Private post title XYZ', (string) wp_json_encode( $diff ) );
	}

	public function test_an_undo_entry_has_the_undo_status_and_no_sections(): void {
		$entry = $this->stored_entry(
			array(
				'ability'           => 'steadhand/undo-change',
				'object_type'       => 'post',
				'object_id'         => $this->admin_post(),
				'result'            => Entry::RESULT_OK,
				'snapshot_id'       => 5,
				'after_snapshot_id' => 6,
			)
		);

		$diff = EntryDiff::for_entry( $entry );

		$this->assertSame( EntryDiff::STATUS_UNDO, $diff['status'] );
		$this->assertSame( '', $diff['kind'] );
		$this->assertSame( array(), $diff['sections'] );
	}

	public function test_a_missing_post_is_unavailable(): void {
		$entry = $this->update_entry();
		wp_delete_post( (int) $entry['object_id'], true );

		$this->view_as_admin();
		$this->assertSame( EntryDiff::STATUS_UNAVAILABLE, EntryDiff::for_entry( $entry )['status'] );
	}
}
