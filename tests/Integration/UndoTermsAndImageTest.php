<?php
/**
 * The undo of set-post-terms and set-featured-image: what it restores, and what it refuses, with nothing changed and the entry
 * unmarked.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Log\Schema;
use Steadhand\Pipeline\MediaWriter;

/**
 * Covers SPEC §5 (undo-change) for the terms and featured-image kinds. The undo is a call the agent could make itself, so every
 * refusal below is one a direct call would meet, or one that says the saved state is no longer usable. Post-text undo is in
 * UndoChangeTest.
 */
final class UndoTermsAndImageTest extends PostWriteTestCase {

	private const UNDO     = 'steadhand/undo-change';
	private const TERMS    = 'steadhand/set-post-terms';
	private const FEATURED = 'steadhand/set-featured-image';

	/** An ID no change-log row has. */
	private const MISSING_ID = 987654321;

	/** The generic refusal for an entry that is not the caller's, or does not exist. */
	private const NOT_FOUND = 'Not found, or this agent may not access it.';

	private const ALREADY = 'This change has already been undone, so it cannot be undone again. Nothing was changed.';

	private const NOT_UNDOABLE = 'Steadhand cannot undo this change. Only a change that update-post, edit-blocks, set-post-terms or set-featured-image made, and that was saved with the state it needs, can be undone. Nothing was changed.';

	private const NO_SAVED_STATE = 'This change has no saved state that Steadhand can restore, so it cannot be undone. Nothing was changed.';

	private const RESTORE_BLOCKED = 'Steadhand cannot undo this change, because the state it would restore is no longer valid: a term or image it names is missing or not usable, or the post can no longer hold it. Nothing was changed.';

	private const TERMS_CHANGED = "The post's terms were changed after that change, so it cannot be undone. Nothing was changed.";

	private const IMAGE_CHANGED = "The post's featured image was changed after that change, so it cannot be undone. Nothing was changed.";

	private const PUBLISH_NEEDED = 'This post is published, scheduled or private. Changing it needs the steadhand_publish capability, which this agent does not hold. Nothing was changed.';

	protected function tearDown(): void {
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		remove_all_filters( 'query' );
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
	 * The post's term IDs in post_tag, ascending.
	 *
	 * @param int $post Post ID.
	 * @return int[]
	 */
	private function tag_ids( int $post ): array {
		$ids = array_map( 'intval', (array) wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Calls set-post-terms as the current user.
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
	 * Calls set-featured-image as the current user.
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
	 * Calls undo-change as the current user.
	 *
	 * @param int $entry Change-log entry ID.
	 */
	private function undo( int $entry ): mixed {
		return $this->execute_ability( self::UNDO, array( 'id' => $entry ) );
	}

	/**
	 * Asserts a WP_Error with a code and message.
	 *
	 * @param mixed  $result  Result.
	 * @param string $code    Expected code.
	 * @param string $message Expected message.
	 */
	private function assert_refused( mixed $result, string $code, string $message ): void {
		$this->assertWPError( $result, 'The undo must be refused.' );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
	}

	/**
	 * Asserts that a refused undo marked nothing.
	 *
	 * @param int $entry Entry ID.
	 */
	private function assert_unmarked( int $entry ): void {
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'], 'A refused undo must not mark the entry.' );
	}

	/**
	 * Makes a terms change as the current agent, and returns the entry ID and the terms before and after.
	 *
	 * @param int[]  $before The terms the post has first.
	 * @param int[]  $sent   Term IDs to send.
	 * @param string $mode   Mode.
	 * @return array{entry: int, post: int, agent: int}
	 */
	private function terms_change( array $before, array $sent, string $mode = 'replace' ): array {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, $before, 'post_tag', false );

		$this->set_terms( $post, $sent, $mode );

		return array(
			'entry' => (int) $this->latest_row( $agent )['id'],
			'post'  => $post,
			'agent' => $agent,
		);
	}

	/**
	 * Makes a featured-image change as the current agent, and returns the entry ID and the post.
	 *
	 * @param int|null $before The image the post has first, or null for none.
	 * @param int      $sent   Attachment ID to send, or 0 to remove.
	 * @return array{entry: int, post: int, agent: int}
	 */
	private function image_change( ?int $before, int $sent ): array {
		$agent = $this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		if ( null !== $before ) {
			set_post_thumbnail( $post, $before );
		}

		$this->set_image( $post, $sent );

		return array(
			'entry' => (int) $this->latest_row( $agent )['id'],
			'post'  => $post,
			'agent' => $agent,
		);
	}

	/**
	 * Changes a snapshot row, as a fixture for a damaged or foreign row.
	 *
	 * @param int                  $id     Snapshot row ID.
	 * @param array<string, mixed> $fields Columns.
	 */
	private function set_snapshot( int $id, array $fields ): void {
		global $wpdb;

		$wpdb->update( Schema::snapshots_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the row is set as another write would leave it.
	}

	/**
	 * Removes a snapshot row.
	 *
	 * @param int $id Snapshot row ID.
	 */
	private function delete_snapshot( int $id ): void {
		global $wpdb;

		$wpdb->delete( Schema::snapshots_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the row is removed.
	}

	/**
	 * The snapshot row IDs of an entry.
	 *
	 * @param int $entry Entry ID.
	 * @return array{before: int, after: int}
	 */
	private function snapshots_of( int $entry ): array {
		$row = $this->stored( $entry );

		return array(
			'before' => (int) $row['snapshot_id'],
			'after'  => (int) $row['after_snapshot_id'],
		);
	}

	public function test_a_replace_is_undone_to_the_exact_previous_set(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$third  = $this->tag( 'Three' );
		$change = $this->terms_change( array( $first, $second ), array( $third ) );

		$this->assertSame( array( $third ), $this->tag_ids( $change['post'] ) );
		$result = $this->undo( $change['entry'] );

		$this->assertSame( array( 'entry_id', 'object_type', 'object_id', 'undone', 'link', 'warnings', 'guardrail_notes' ), array_keys( $result ) );
		$this->assertSame( 'post', $result['object_type'] );
		$this->assertSame( $change['post'], $result['object_id'] );
		$this->assertTrue( $result['undone'] );
		$this->assertSame( array( min( $first, $second ), max( $first, $second ) ), $this->tag_ids( $change['post'] ) );
	}

	public function test_an_add_is_undone_to_the_exact_previous_set(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ), 'add' );

		$this->undo( $change['entry'] );

		$this->assertSame( array( $first ), $this->tag_ids( $change['post'] ) );
	}

	public function test_a_remove_is_undone_to_the_exact_previous_set(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first, $second ), array( $first ), 'remove' );

		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
		$this->undo( $change['entry'] );

		$this->assertSame( array( min( $first, $second ), max( $first, $second ) ), $this->tag_ids( $change['post'] ) );
	}

	public function test_a_terms_undo_that_restores_an_empty_set_clears_the_taxonomy(): void {
		$first  = $this->tag( 'One' );
		$change = $this->terms_change( array(), array( $first ) );

		$this->undo( $change['entry'] );

		$this->assertSame( array(), $this->tag_ids( $change['post'] ) );
	}

	public function test_an_image_set_on_a_post_with_none_is_undone_to_none(): void {
		$image  = $this->image();
		$change = $this->image_change( null, $image );
		$this->assertSame( $image, MediaWriter::stored_thumbnail( $change['post'] ) );

		$this->undo( $change['entry'] );

		$this->assertSame( 0, MediaWriter::stored_thumbnail( $change['post'] ) );
	}

	public function test_an_image_replace_is_undone_to_the_previous_image(): void {
		$old    = $this->image();
		$new    = $this->image();
		$change = $this->image_change( $old, $new );

		$this->undo( $change['entry'] );

		$this->assertSame( $old, MediaWriter::stored_thumbnail( $change['post'] ) );
	}

	public function test_an_image_removal_is_undone_to_the_previous_image(): void {
		$old    = $this->image();
		$change = $this->image_change( $old, 0 );
		$this->assertSame( 0, MediaWriter::stored_thumbnail( $change['post'] ) );

		$this->undo( $change['entry'] );

		$this->assertSame( $old, MediaWriter::stored_thumbnail( $change['post'] ) );
	}

	public function test_an_undo_marks_its_entry_with_its_own_row_and_is_not_undoable_itself(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );

		$this->undo( $change['entry'] );

		$undo = $this->latest_row( $change['agent'] );
		$this->assertSame( self::UNDO, $undo['ability'] );
		$this->assertSame( 'ok', $undo['result'] );
		$this->assertSame( 0, (int) $undo['undoable'], 'The undo itself cannot be undone.' );
		$this->assertSame( (int) $undo['id'], (int) $this->stored( $change['entry'] )['undone_by'] );
	}

	public function test_terms_changed_since_by_another_user_are_refused_and_nothing_changes(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$other  = $this->tag( 'Other' );
		$change = $this->terms_change( array( $first ), array( $second ) );
		wp_set_object_terms( $change['post'], array( $other ), 'post_tag', false );

		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_changed_since', self::TERMS_CHANGED );

		$this->assertSame( array( $other ), $this->tag_ids( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_a_featured_image_changed_since_by_another_user_is_refused_and_nothing_changes(): void {
		$old    = $this->image();
		$new    = $this->image();
		$other  = $this->image();
		$change = $this->image_change( $old, $new );
		update_post_meta( $change['post'], '_thumbnail_id', $other );

		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_changed_since', self::IMAGE_CHANGED );

		$this->assertSame( $other, MediaWriter::stored_thumbnail( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_another_agents_entry_is_refused_with_the_text_of_a_missing_id(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );

		$this->act_as_agent();
		$generic = $this->undo( $change['entry'] );
		$missing = $this->undo( self::MISSING_ID );

		$this->assert_refused( $generic, 'steadhand_forbidden', self::NOT_FOUND );
		$this->assert_refused( $missing, 'steadhand_forbidden', self::NOT_FOUND );
		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_an_already_undone_change_is_refused_and_the_second_undo_changes_nothing(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );
		$this->undo( $change['entry'] );
		$this->assertSame( array( $first ), $this->tag_ids( $change['post'] ) );

		wp_set_object_terms( $change['post'], array( $second ), 'post_tag', false );
		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_forbidden', self::ALREADY );

		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
	}

	public function test_a_call_that_changed_nothing_has_no_undo_and_is_refused(): void {
		$first  = $this->tag( 'One' );
		$change = $this->terms_change( array( $first ), array( $first ) );

		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_forbidden', self::NOT_UNDOABLE );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_a_term_of_the_saved_state_deleted_since_is_refused_as_not_undoable(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );
		wp_delete_term( $first, 'post_tag' );

		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::RESTORE_BLOCKED );

		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_an_image_of_the_saved_state_deleted_since_is_refused_with_the_generic_text(): void {
		$old    = $this->image();
		$new    = $this->image();
		$change = $this->image_change( $old, $new );
		wp_delete_attachment( $old, true );

		// No existence pre-check: the object check refuses it as it refuses any image the caller cannot read.
		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_forbidden', self::NOT_FOUND );

		$this->assertSame( $new, MediaWriter::stored_thumbnail( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_an_image_of_the_saved_state_that_is_no_longer_an_image_is_refused_as_not_undoable(): void {
		global $wpdb;

		$old    = $this->image();
		$new    = $this->image();
		$change = $this->image_change( $old, $new );
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'application/pdf' ), array( 'ID' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the attachment is no longer an image.
		clean_post_cache( $old );

		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::RESTORE_BLOCKED );

		$this->assert_unmarked( $change['entry'] );
	}

	public function test_a_missing_snapshot_row_is_refused_as_not_undoable_for_terms_and_for_the_image(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$terms  = $this->terms_change( array( $first ), array( $second ) );
		$this->delete_snapshot( $this->snapshots_of( $terms['entry'] )['before'] );
		$this->assert_refused( $this->undo( $terms['entry'] ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );
		$this->assert_unmarked( $terms['entry'] );

		$image = $this->image_change( $this->image(), $this->image() );
		$this->delete_snapshot( $this->snapshots_of( $image['entry'] )['after'] );
		$this->assert_refused( $this->undo( $image['entry'] ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );
		$this->assert_unmarked( $image['entry'] );
	}

	/**
	 * The damaging changes of one saved state, each applied to the before row of a terms change and of an image change:
	 * another post, another kind, another user, malformed JSON, an extra key, and (terms only) another taxonomy. Each is refused
	 * as not undoable, and nothing changes.
	 */
	public function test_a_saved_state_that_is_not_the_one_the_entry_names_is_refused_as_not_undoable(): void {
		$other_post = $this->admin_post();
		$other_user = $this->other_agent();

		$cases = array(
			'another post'   => array( 'object_id' => $other_post ),
			'another kind'   => array( 'kind' => 'featured_image' ),
			'another user'   => array( 'user_id' => $other_user ),
			'malformed JSON' => array( 'state' => '{"taxonomy":' ),
		);

		foreach ( $cases as $case => $fields ) {
			$first  = $this->tag( "A $case" );
			$second = $this->tag( "B $case" );
			$change = $this->terms_change( array( $first ), array( $second ) );
			$this->set_snapshot( $this->snapshots_of( $change['entry'] )['before'], $fields );

			$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );
			$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ), "$case must change nothing." );
			$this->assert_unmarked( $change['entry'] );
		}

		$first  = $this->tag( 'Extra A' );
		$second = $this->tag( 'Extra B' );
		$change = $this->terms_change( array( $first ), array( $second ) );
		$this->set_snapshot( $this->snapshots_of( $change['entry'] )['before'], array( 'state' => '{"taxonomy":"post_tag","term_ids":[' . $first . '],"extra":1}' ) );
		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );
		$this->assert_unmarked( $change['entry'] );

		$first  = $this->tag( 'Taxonomy A' );
		$second = $this->tag( 'Taxonomy B' );
		$change = $this->terms_change( array( $first ), array( $second ) );
		$this->set_snapshot( $this->snapshots_of( $change['entry'] )['before'], array( 'state' => '{"taxonomy":"category","term_ids":[' . $first . ']}' ) );
		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );
		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );

		$old    = $this->image();
		$new    = $this->image();
		$change = $this->image_change( $old, $new );
		$this->set_snapshot( $this->snapshots_of( $change['entry'] )['before'], array( 'object_id' => $other_post ) );
		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );
		$this->assert_unmarked( $change['entry'] );

		$old    = $this->image();
		$new    = $this->image();
		$change = $this->image_change( $old, $new );
		$this->set_snapshot( $this->snapshots_of( $change['entry'] )['before'], array( 'kind' => 'post_terms' ) );
		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );
		$this->assert_unmarked( $change['entry'] );

		$old    = $this->image();
		$new    = $this->image();
		$change = $this->image_change( $old, $new );
		$this->set_snapshot( $this->snapshots_of( $change['entry'] )['before'], array( 'state' => '{"featured_media":' . $old . ',"extra":1}' ) );
		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_a_trashed_post_is_refused_with_the_generic_text_and_nothing_changes(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );
		wp_trash_post( $change['post'] );

		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_forbidden', self::NOT_FOUND );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_a_post_published_since_the_change_needs_steadhand_publish_for_the_terms_and_the_image(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$terms  = $this->terms_change( array( $first ), array( $second ) );
		$image  = $this->image_change( $this->image(), $this->image() );

		wp_update_post(
			array(
				'ID'          => $terms['post'],
				'post_status' => 'publish',
			)
		);
		wp_update_post(
			array(
				'ID'          => $image['post'],
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( $terms['agent'] );
		$this->assert_refused( $this->undo( $terms['entry'] ), 'steadhand_forbidden', self::PUBLISH_NEEDED );
		$this->assert_unmarked( $terms['entry'] );
		$this->assertSame( array( $second ), $this->tag_ids( $terms['post'] ) );

		wp_set_current_user( $image['agent'] );
		$this->assert_refused( $this->undo( $image['entry'] ), 'steadhand_forbidden', self::PUBLISH_NEEDED );
		$this->assert_unmarked( $image['entry'] );
	}

	public function test_an_agent_that_no_longer_holds_the_capability_the_entry_needed_is_refused_with_the_generic_text(): void {
		// Terms: this half does not isolate capability_held(). set-post-terms' object check needs steadhand_write_drafts for a draft
		// (through MetaCaps' edit_post mapping) and steadhand_publish for a published post, so with neither held the object check
		// refuses on its own. No post state gives a call that passes the object check without one of those two capabilities.
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$terms  = $this->terms_change( array( $first ), array( $second ) );
		$this->revoke_and_reload( $terms['agent'], Capabilities::WRITE_DRAFTS, Capabilities::MEDIA );

		$this->assert_refused( $this->undo( $terms['entry'] ), 'steadhand_forbidden', self::NOT_FOUND );
		$this->assert_unmarked( $terms['entry'] );
		$this->assertSame( array( $second ), $this->tag_ids( $terms['post'] ) );

		// Image: the only half that isolates capability_held(). set-featured-image's object check does not look at steadhand_media
		// (the pipeline checks it only for the call itself), so without capability_held() this undo would be allowed. The agent keeps
		// steadhand_write_drafts, which the pipeline's union accepts.
		$image = $this->image_change( $this->image(), $this->image() );
		$this->revoke_and_reload( $image['agent'], Capabilities::MEDIA, '' );

		$this->assert_refused( $this->undo( $image['entry'] ), 'steadhand_forbidden', self::NOT_FOUND );
		$this->assert_unmarked( $image['entry'] );
	}

	/**
	 * Changes an agent's capabilities and makes it the current user again, with its capabilities read afresh.
	 * wp_set_current_user() returns early for the user already current, so the user is cleared first.
	 *
	 * @param int    $agent   Agent user ID.
	 * @param string $revoke  Capability to set to false.
	 * @param string $grant   Capability to set to true, or '' for none.
	 */
	private function revoke_and_reload( int $agent, string $revoke, string $grant ): void {
		$user = get_userdata( $agent );
		$user->add_cap( $revoke, false );
		if ( '' !== $grant ) {
			$user->add_cap( $grant, true );
		}

		wp_set_current_user( 0 );
		wp_set_current_user( $agent );
	}

	public function test_the_undo_is_subject_to_the_rate_limit(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 1 );

		$result = $this->undo( $change['entry'] );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_the_undo_is_subject_to_the_edit_lock_on_its_post(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );
		$human  = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_post_meta( $change['post'], '_edit_lock', time() . ':' . $human );

		$result = $this->undo( $change['entry'] );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	/** The warning a moved permalink gives, as set-post-terms gives it. */
	private const MOVED = 'This change moved the post to a new address. WordPress does not redirect the old one.';

	/** The warning a post left with no category gives, as set-post-terms gives it. */
	private const DEFAULT_CATEGORY = 'WordPress gives a post with no category the default category the next time the post is saved.';

	private const SHELF     = 'undo_shelf';
	private const SHELF_CAP = 'steadhand_undo_shelf_assign';

	private function category( string $name ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => $name,
			)
		);
	}

	public function test_a_taxonomy_removed_from_the_allowlist_since_is_refused_as_not_undoable(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );

		unregister_taxonomy_for_object_type( 'post_tag', 'post' );
		try {
			$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_refused_not_undoable', self::RESTORE_BLOCKED );
		} finally {
			register_taxonomy_for_object_type( 'post_tag', 'post' );
		}

		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_a_custom_assign_capability_revoked_since_is_refused_with_the_generic_text(): void {
		register_taxonomy(
			self::SHELF,
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => self::SHELF_CAP ),
			)
		);
		try {
			$agent  = $this->act_as_agent( array( self::SHELF_CAP => true ) );
			$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
			$first  = (int) self::factory()->term->create(
				array(
					'taxonomy' => self::SHELF,
					'name'     => 'First',
				)
			);
			$second = (int) self::factory()->term->create(
				array(
					'taxonomy' => self::SHELF,
					'name'     => 'Second',
				)
			);
			wp_set_object_terms( $post, array( $first ), self::SHELF, false );
			$this->execute_ability(
				self::TERMS,
				array(
					'id'       => $post,
					'taxonomy' => self::SHELF,
					'terms'    => array( $second ),
					'mode'     => 'replace',
				)
			);
			$entry = (int) $this->latest_row( $agent )['id'];

			$this->revoke_and_reload( $agent, self::SHELF_CAP, '' );

			$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::NOT_FOUND );
			$this->assertSame( array( $second ), array_map( 'intval', (array) wp_get_object_terms( $post, self::SHELF, array( 'fields' => 'ids' ) ) ) );
			$this->assert_unmarked( $entry );
		} finally {
			unregister_taxonomy( self::SHELF );
		}
	}

	public function test_an_image_the_caller_can_no_longer_read_since_is_refused_with_the_generic_text(): void {
		global $wpdb;

		$this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );
		$old  = $this->image();
		$new  = $this->image();
		set_post_thumbnail( $post, $old );
		$this->set_image( $post, $new );
		$entry = (int) $this->latest_row( get_current_user_id() )['id'];

		// The media rule needs an attachment whose own status is inherit. A draft attachment is not readable under it.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the attachment is no longer within the media rule.
		clean_post_cache( $old );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::NOT_FOUND );
		$this->assertSame( $new, MediaWriter::stored_thumbnail( $post ) );
		$this->assert_unmarked( $entry );
	}

	public function test_a_post_type_no_longer_allowed_since_is_refused_with_the_generic_text(): void {
		$first  = $this->tag( 'One' );
		$second = $this->tag( 'Two' );
		$change = $this->terms_change( array( $first ), array( $second ) );

		add_filter( 'steadhand_allowed_post_types', static fn( array $types ): array => array_values( array_diff( $types, array( 'post' ) ) ) );

		$this->assert_refused( $this->undo( $change['entry'] ), 'steadhand_forbidden', self::NOT_FOUND );
		$this->assertSame( array( $second ), $this->tag_ids( $change['post'] ) );
		$this->assert_unmarked( $change['entry'] );
	}

	public function test_a_terms_undo_gives_the_moved_permalink_warning_the_direct_call_gives(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%category%/%postname%/' );
		$wp_rewrite->flush_rules();

		try {
			$agent = $this->act_as_agent( array( Capabilities::PUBLISH => true ) );
			$post  = $this->admin_post(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Moved',
				)
			);
			$alpha = $this->category( 'Alpha' );
			$beta  = $this->category( 'Beta' );
			wp_set_object_terms( $post, array( $alpha ), 'category', false );

			$direct = $this->execute_ability(
				self::TERMS,
				array(
					'id'       => $post,
					'taxonomy' => 'category',
					'terms'    => array( $beta ),
					'mode'     => 'replace',
				)
			);
			$this->assertContains( self::MOVED, $direct['warnings'] );

			$entry = (int) $this->latest_row( $agent )['id'];
			$undo  = $this->undo( $entry );

			$this->assertSame( array( $alpha ), array_map( 'intval', (array) wp_get_object_terms( $post, 'category', array( 'fields' => 'ids' ) ) ) );
			$this->assertContains( self::MOVED, $undo['warnings'], 'The undo gives the warning the equivalent direct call gives.' );
		} finally {
			$wp_rewrite->set_permalink_structure( '' );
			$wp_rewrite->flush_rules();
		}
	}

	public function test_a_terms_undo_that_leaves_no_category_gives_the_default_category_warning_the_direct_call_gives(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$alpha = $this->category( 'Alpha' );
		wp_set_object_terms( $post, array(), 'category', false );

		$direct = $this->execute_ability(
			self::TERMS,
			array(
				'id'       => $post,
				'taxonomy' => 'category',
				'terms'    => array( $alpha ),
				'mode'     => 'replace',
			)
		);
		$this->assertNotContains( self::DEFAULT_CATEGORY, $direct['warnings'] );

		$entry = (int) $this->latest_row( $agent )['id'];
		$undo  = $this->undo( $entry );

		$this->assertContains( self::DEFAULT_CATEGORY, $undo['warnings'] );
	}
}
