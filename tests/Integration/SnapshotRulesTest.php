<?php
/**
 * S4: a snapshot is never an autosave, a revision is matched to the post, and undoable is true only for content changes.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Integration tests for snapshots: autosaves, matching and the undoable rules (S4).
 */
final class SnapshotRulesTest extends PostWriteTestCase {

	/**
	 * The warning for a call that sets status, slug or date.
	 */
	private const METADATA_WARNING = 'This change includes status, slug or date, which Steadhand cannot undo yet.';

	/**
	 * Creates a draft with the given content, and an agent that may publish. Returns the post ID.
	 *
	 * @param string $content Content of the draft.
	 */
	private function draft_and_agent( string $content = 'v1' ): int {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => $content,
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		return $post;
	}

	/**
	 * The snapshot ID and undoable flag of the last log row of the agent.
	 *
	 * @return array{snapshot_id: int, undoable: int}
	 */
	private function snapshot_of_last_row(): array {
		$row = $this->latest_row( get_current_user_id() );

		return array(
			'snapshot_id' => (int) $row['snapshot_id'],
			'undoable'    => (int) $row['undoable'],
		);
	}

	public function test_an_autosave_newer_than_the_last_revision_is_never_the_snapshot(): void {
		$post = $this->draft_and_agent( 'v1' );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'v2',
			)
		);
		wp_set_current_user( $this->admin_id() );
		$autosave = _wp_put_post_revision( get_post( $post ), true );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Makes the autosave the newest revision.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + 5 ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 5 ),
			),
			array( 'ID' => $autosave )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Makes the autosave the newest revision.
		clean_post_cache( $autosave );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'agent v3',
			)
		);

		$this->assertIsArray( $result );
		$snapshot = $this->snapshot_of_last_row();
		$this->assertNotSame( $autosave, $snapshot['snapshot_id'], 'An autosave is never a snapshot.' );
		$this->assertGreaterThan( 0, $snapshot['snapshot_id'], 'A revision of the current content is saved.' );
		$this->assertFalse( (bool) wp_is_post_autosave( get_post( $snapshot['snapshot_id'] ) ) );
		$this->assertSame( 'v2', get_post( $snapshot['snapshot_id'] )->post_content, 'The revision holds the content before the change.' );
	}

	public function test_a_content_only_change_is_undoable_and_has_a_revision(): void {
		$post = $this->draft_and_agent( 'v1' );

		$this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'v2',
			)
		);

		$snapshot = $this->snapshot_of_last_row();
		$this->assertGreaterThan( 0, $snapshot['snapshot_id'] );
		$this->assertSame( 1, $snapshot['undoable'] );
		$this->assertSame( 'v1', get_post( $snapshot['snapshot_id'] )->post_content );
	}

	public function test_a_status_only_change_has_a_revision_but_is_not_undoable_and_says_so(): void {
		$post = $this->draft_and_agent( 'v1' );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $post,
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $out );
		$this->assertContains( self::METADATA_WARNING, $out['warnings'] );
		$snapshot = $this->snapshot_of_last_row();
		$this->assertGreaterThan( 0, $snapshot['snapshot_id'], 'A revision was obtained, so its ID is kept.' );
		$this->assertSame( 0, $snapshot['undoable'] );
	}

	public function test_a_content_and_status_change_has_a_snapshot_id_but_is_not_undoable(): void {
		$post = $this->draft_and_agent( 'v1' );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'v2',
				'status'  => 'pending',
			)
		);

		$this->assertIsArray( $out );
		$this->assertContains( self::METADATA_WARNING, $out['warnings'] );
		$snapshot = $this->snapshot_of_last_row();
		$this->assertGreaterThan( 0, $snapshot['snapshot_id'] );
		$this->assertSame( 0, $snapshot['undoable'] );
	}

	public function test_a_slug_change_is_not_undoable_and_says_so(): void {
		$post = $this->draft_and_agent( 'v1' );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'   => $post,
				'slug' => 'a-new-slug',
			)
		);

		$this->assertIsArray( $out );
		$this->assertContains( self::METADATA_WARNING, $out['warnings'] );
		$this->assertSame( 0, $this->snapshot_of_last_row()['undoable'] );
	}

	public function test_a_revision_that_does_not_match_the_post_is_not_returned(): void {
		$post = $this->draft_and_agent( 'old' );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'middle',
			)
		);
		$older = $this->revision_ids( $post );
		$this->assertNotEmpty( $older, 'An older revision exists, holding the content "old".' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Changes the post with no revision.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Changes the post with no revision.
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'changed directly' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Changes the post with no revision.
		clean_post_cache( $post );
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'agent',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 0, $this->snapshot_of_last_row()['snapshot_id'], 'The only revision holds different content, so it is not the snapshot.' );
		$this->assertSame( 0, $this->snapshot_of_last_row()['undoable'] );
		$this->assertContains( 'This site keeps too few revisions for Steadhand to undo this change.', $out['warnings'] );
	}


	public function test_with_one_revision_kept_the_change_is_not_undoable_and_the_site_is_told_why(): void {
		add_filter( 'wp_revisions_to_keep', static fn(): int => 1 );
		$post = $this->draft_and_agent( 'old' );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'new',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'new', get_post( $post )->post_content );
		$this->assertSame(
			array(
				'snapshot_id' => 0,
				'undoable'    => 0,
			),
			$this->snapshot_of_last_row()
		);
		$this->assertContains( 'This site keeps too few revisions for Steadhand to undo this change.', $out['warnings'] );
	}

	public function test_with_two_revisions_kept_the_change_is_undoable_and_the_snapshot_holds_the_old_content(): void {
		add_filter( 'wp_revisions_to_keep', static fn(): int => 2 );
		$post = $this->draft_and_agent( 'old' );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'new',
			)
		);

		$this->assertIsArray( $out );
		$snapshot = $this->snapshot_of_last_row();
		$this->assertGreaterThan( 0, $snapshot['snapshot_id'] );
		$this->assertSame( 1, $snapshot['undoable'] );
		$this->assertSame( 'old', get_post( $snapshot['snapshot_id'] )->post_content );
		$this->assertNotContains( 'This site keeps too few revisions for Steadhand to undo this change.', $out['warnings'] );
	}

	public function test_with_unlimited_revisions_kept_the_change_is_undoable(): void {
		add_filter( 'wp_revisions_to_keep', static fn(): int => -1 );
		$post = $this->draft_and_agent( 'old' );

		$this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'new',
			)
		);

		$this->assertSame( 1, $this->snapshot_of_last_row()['undoable'] );
	}
}
