<?php
/**
 * The steadhand/undo-change ability: what it restores, what it refuses, what the change log records, and the checks made
 * inside the save itself.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Log\Schema;
use Steadhand\Pipeline\PostWriter;
use WP\MCP\Abilities\McpAbilityExposure;

/**
 * Covers SPEC §5 (undo-change) and §7. Negative tests are the bulk of it: an undo that is refused must leave the post
 * byte-identical and must not mark the change-log entry. A second write to the row, made by a test with $wpdb or a filter,
 * stands in for another request.
 */
final class UndoChangeTest extends PostWriteTestCase {

	private const UNDO_TOOL = 'steadhand-undo-change';
	private const ABILITY   = 'steadhand/undo-change';
	private const UPDATE    = 'steadhand-update-post';
	private const EDIT      = 'steadhand-edit-blocks';

	/** The generic refusal for an entry that is not the caller's, or does not exist. */
	private const NOT_FOUND = 'Not found, or this agent may not access it.';

	/** The refusal for a change that cannot be undone, when the caller can read the entry. */
	private const NOT_UNDOABLE = 'Steadhand cannot undo this change. Only a change to a post\'s title, content or excerpt that update-post or edit-blocks made, and that was saved with its revisions, can be undone. Nothing was changed.';

	/** The refusal for an entry that was already undone. */
	private const ALREADY = 'This change has already been undone, so it cannot be undone again. Nothing was changed.';

	/** The refusal for a saved state that no longer exists. */
	private const NO_SAVED_STATE = 'This change has no saved state that Steadhand can restore, so it cannot be undone. Nothing was changed.';

	/** The refusal for a post whose page template the active theme does not have. */
	private const TEMPLATE = 'This post uses a page template that the active theme does not have. WordPress would save the change and then report an error. Nothing was changed. An administrator must set a valid template for this post.';

	/** The refusal for a post edited after the change. */
	private const CHANGED_SINCE = 'The post was edited after that change, so it cannot be undone. Nothing was changed.';

	/** The publish-needed refusal update-post gives. */
	private const PUBLISH_NEEDED = 'This post is published, scheduled or private. Changing it needs the steadhand_publish capability, which this agent does not hold. Nothing was changed.';

	/** An ID that no change-log row has. */
	private const MISSING_ID = 987654321;

	/** Filters this file adds, removed in tearDown. */
	private const FILTERS = array( 'steadhand_rate_limit_per_minute' );

	protected function tearDown(): void {
		foreach ( self::FILTERS as $filter ) {
			remove_all_filters( $filter );
		}
		remove_all_filters( 'query' );
		parent::tearDown();
	}

	private static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * A draft by the administrator, with a known title, content and excerpt.
	 *
	 * @param array<string, mixed> $fields Overrides.
	 */
	private function fixture( array $fields = array() ): int {
		return $this->admin_post(
			array_merge(
				array(
					'post_status'  => 'draft',
					'post_title'   => 'Before title',
					'post_content' => self::paragraph( 'Before' ),
					'post_excerpt' => 'Before excerpt',
				),
				$fields
			)
		);
	}

	/**
	 * Makes a change as the current agent with update-post, and returns the change-log entry it wrote.
	 *
	 * @param int                  $post   Post ID.
	 * @param array<string, mixed> $fields Fields to send.
	 */
	private function changed( int $post, array $fields ): int {
		$this->output_of( self::UPDATE, array( 'id' => $post ) + $fields );

		return (int) $this->latest_row( get_current_user_id() )['id'];
	}

	/**
	 * Calls undo-change through the pipeline, as the MCP path does, and returns the result.
	 *
	 * @param int $entry Change-log entry ID.
	 * @return mixed
	 */
	private function undo( int $entry ): mixed {
		return $this->execute_ability( self::ABILITY, array( 'id' => $entry ) );
	}

	/**
	 * Sets columns of one change-log row directly, as a fixture.
	 *
	 * @param int                  $entry  Row ID.
	 * @param array<string, mixed> $fields Columns.
	 */
	private function set_entry( int $entry, array $fields ): void {
		global $wpdb;

		$wpdb->update( Schema::table(), $fields, array( 'id' => $entry ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the row is set as another write would leave it.
	}

	/**
	 * Changes the post's content directly in its row, as another request would, with no revision.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content New content.
	 */
	private function direct_content( int $post_id, string $content ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Another request's row write.
		clean_post_cache( $post_id );
	}

	/**
	 * Asserts that a refusal leaves the post as it was and the entry unmarked.
	 *
	 * @param array<string, string> $before Post row before the call.
	 * @param int                   $post   Post ID.
	 * @param int                   $entry  Entry ID.
	 */
	private function assert_nothing_changed( array $before, int $post, int $entry ): void {
		$this->assertSame( $before, $this->row( $post ), 'A refused undo must leave the post byte-identical.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'], 'A refused undo must not mark the entry.' );
	}

	/**
	 * Asserts a WP_Error with a code and message.
	 *
	 * @param mixed  $result  Result.
	 * @param string $code    Expected code.
	 * @param string $message Expected message, or '' to skip the message.
	 */
	private function assert_refused( mixed $result, string $code, string $message = '' ): void {
		$this->assertWPError( $result, 'The undo must be refused.' );
		$this->assertSame( $code, $result->get_error_code() );
		if ( '' !== $message ) {
			$this->assertSame( $message, $result->get_error_message() );
		}
	}

	/**
	 * Makes the next UPDATE of the posts table fail: the query is blanked, so wpdb::update() returns false.
	 */
	private function fail_the_posts_update(): void {
		add_filter(
			'query',
			static function ( string $sql ): string {
				global $wpdb;

				return str_starts_with( ltrim( $sql ), 'UPDATE `' . $wpdb->posts . '`' ) ? '' : $sql;
			},
			PHP_INT_MAX - 2
		);
	}

	public function test_is_registered_with_strict_schemas_annotations_and_is_not_public(): void {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability );

		$input = $ability->get_input_schema();
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame( array( 'id' ), $input['required'] );
		$this->assertSame( array( 'id' ), array_keys( $input['properties'] ) );
		$this->assertSame( 1, $input['properties']['id']['minimum'] );

		$output = $ability->get_output_schema();
		$this->assertFalse( $output['additionalProperties'] );
		$this->assertSame( array( 'entry_id', 'object_type', 'object_id', 'undone', 'link', 'warnings', 'guardrail_notes' ), $output['required'] );

		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['idempotent'] );

		$this->assertFalse( McpAbilityExposure::is_public( $ability ) );
		$this->assertFalse( McpAbilityExposure::is_meta_public( $meta ) );

		$this->act_as_agent();
		$names = wp_list_pluck( $this->body( $this->mcp( 'tools/list', array(), $this->mcp_session() ) )['result']['tools'], 'name' );
		$this->assertContains( self::UNDO_TOOL, $names );
	}

	public function test_an_update_post_change_is_undone_to_the_exact_title_content_and_excerpt(): void {
		$post  = $this->fixture();
		$agent = $this->act_as_agent();
		$entry = $this->changed(
			$post,
			array(
				'title'   => 'After title',
				'content' => self::paragraph( 'After' ),
				'excerpt' => 'After excerpt',
			)
		);

		$out = $this->output_of( self::UNDO_TOOL, array( 'id' => $entry ) );

		$this->assertSame( $entry, $out['entry_id'] );
		$this->assertSame( 'post', $out['object_type'] );
		$this->assertSame( $post, $out['object_id'] );
		$this->assertTrue( $out['undone'] );
		$this->assertSame( array(), $out['warnings'] );

		$restored = get_post( $post );
		$this->assertSame( 'Before title', $restored->post_title );
		$this->assertSame( self::paragraph( 'Before' ), $restored->post_content );
		$this->assertSame( 'Before excerpt', $restored->post_excerpt );
		$this->assertSame( 'draft', $restored->post_status, 'Status is not changed by an undo.' );
		$this->assertSame( $this->admin_id(), (int) $restored->post_author, 'The author of the post is not changed.' );
	}

	public function test_an_edit_blocks_change_is_undone_to_the_exact_content(): void {
		$original = self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' );
		$post     = $this->fixture( array( 'post_content' => $original ) );
		$this->act_as_agent();

		$this->output_of(
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
		$entry = (int) $this->latest_row( get_current_user_id() )['id'];
		$this->assertSame( 'steadhand/edit-blocks', $this->stored( $entry )['ability'] );

		$this->output_of( self::UNDO_TOOL, array( 'id' => $entry ) );

		$this->assertSame( $original, get_post( $post )->post_content );
	}

	public function test_the_entry_is_marked_with_the_undo_call_s_own_row_and_the_change_log_shows_it_undone(): void {
		$post  = $this->fixture();
		$agent = $this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );

		$this->output_of( self::UNDO_TOOL, array( 'id' => $entry ) );

		$undo_row = $this->latest_row( $agent );
		$this->assertSame( self::ABILITY, $undo_row['ability'] );
		$this->assertSame( 'ok', $undo_row['result'] );
		$this->assertSame( 'post', $undo_row['object_type'] );
		$this->assertSame( $post, (int) $undo_row['object_id'] );
		$this->assertSame( 0, (int) $undo_row['undoable'], 'An undo is not itself undoable.' );
		$this->assertSame( 0, (int) $undo_row['snapshot_id'] );
		$this->assertSame( 0, (int) $undo_row['after_snapshot_id'] );
		$this->assertSame( 0, (int) $undo_row['undone_by'], 'The undo row is not marked.' );

		$this->assertSame( (int) $undo_row['id'], (int) $this->stored( $entry )['undone_by'], 'The entry names the undo call\'s own row.' );

		$items = $this->output_of( 'steadhand-get-change-log', array( 'per_page' => 50 ) )['items'];
		$found = array_values( array_filter( $items, static fn( array $item ): bool => $item['id'] === $entry ) );
		$this->assertCount( 1, $found );
		$this->assertTrue( $found[0]['undone'] );
		$this->assertTrue( $found[0]['undoable'] );
	}

	public function test_restored_bytes_are_the_administrator_s_bytes_even_where_the_save_filters_would_change_them(): void {
		wp_set_current_user( $this->admin_id() );
		$stored = self::paragraph( 'Kept' ) . "\n\n<!-- wp:html -->\n<iframe src=\"https://example.com/embed\"></iframe>\n<!-- /wp:html -->";
		$post   = $this->fixture( array( 'post_content' => $stored ) );
		$this->assertStringContainsString( '<iframe', get_post( $post )->post_content, 'The administrator\'s markup is stored.' );

		$this->act_as_agent();
		$this->assertNotSame( $stored, wp_kses_post( $stored ), 'The save filters for the agent would change this markup.' );
		$entry = $this->changed( $post, array( 'content' => self::paragraph( 'Agent text' ) ) );

		$this->output_of( self::UNDO_TOOL, array( 'id' => $entry ) );

		$this->assertSame( $stored, get_post( $post )->post_content, 'The administrator\'s bytes come back exactly.' );
		$this->assertSame( 1, (int) $this->stored( $entry )['undoable'] );
	}

	public function test_restored_text_skips_the_content_rules_and_is_stored_as_it_was(): void {
		wp_set_current_user( $this->admin_id() );
		$unbalanced = "<!-- wp:paragraph -->\n<p>Never closed";
		$post       = $this->fixture( array( 'post_content' => $unbalanced ) );
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'content' => self::paragraph( 'Agent text' ) ) );

		$out = $this->output_of( self::UNDO_TOOL, array( 'id' => $entry ) );

		$this->assertTrue( $out['undone'] );
		$this->assertSame( $unbalanced, get_post( $post )->post_content );
	}

	public function test_a_change_that_went_back_and_forth_is_refused_because_a_newer_revision_exists(): void {
		$post = $this->fixture( array( 'post_title' => 'A' ) );
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'B' ) );

		// A person edits to C, then back to B: the row holds the text the change left, but newer revisions exist.
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'C',
			)
		);
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'B',
			)
		);
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_changed_since', self::CHANGED_SINCE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_an_entry_of_another_agent_is_refused_with_the_same_text_as_a_missing_id(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry  = $this->changed( $post, array( 'title' => 'Owned' ) );
		$before = $this->row( $post );

		$this->act_as_agent();
		$foreign = $this->undo( $entry );
		$missing = $this->undo( self::MISSING_ID );

		$this->assert_refused( $foreign, 'steadhand_forbidden', self::NOT_FOUND );
		$this->assert_refused( $missing, 'steadhand_forbidden', self::NOT_FOUND );
		$this->assertSame( $foreign->get_error_message(), $missing->get_error_message() );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_missing_entry_id_is_refused_with_the_generic_text(): void {
		$this->act_as_agent();

		$this->assert_refused( $this->undo( self::MISSING_ID ), 'steadhand_forbidden', self::NOT_FOUND );
	}

	public function test_an_entry_that_is_not_undoable_is_refused_and_nothing_changes(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		$this->set_entry( $entry, array( 'undoable' => 0 ) );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::NOT_UNDOABLE );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_an_entry_that_was_already_undone_is_refused_and_the_second_undo_changes_nothing(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		$this->output_of( self::UNDO_TOOL, array( 'id' => $entry ) );
		$after_first = $this->row( $post );
		$undone_by   = (int) $this->stored( $entry )['undone_by'];

		$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::ALREADY );

		$this->assertSame( $after_first, $this->row( $post ) );
		$this->assertSame( $undone_by, (int) $this->stored( $entry )['undone_by'], 'The first undo keeps the mark.' );
	}

	public function test_an_entry_of_another_ability_is_refused_and_nothing_changes(): void {
		$post   = $this->fixture();
		$agent  = $this->act_as_agent();
		$before = $this->row( $post );

		foreach ( array( 'steadhand/create-post', 'steadhand/set-post-terms', 'steadhand/set-featured-image', 'steadhand/upload-media' ) as $ability ) {
			$entry = $this->insert_row(
				array(
					'user_id'           => $agent,
					'ability'           => $ability,
					'object_type'       => 'post',
					'object_id'         => $post,
					'result'            => 'ok',
					'undoable'          => 1,
					'snapshot_id'       => 1,
					'after_snapshot_id' => 2,
				)
			);

			$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::NOT_UNDOABLE );
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_a_blocked_or_failed_entry_is_refused_and_nothing_changes(): void {
		$post   = $this->fixture();
		$agent  = $this->act_as_agent();
		$before = $this->row( $post );

		foreach ( array( 'blocked', 'error' ) as $result ) {
			$entry = $this->insert_row(
				array(
					'user_id'           => $agent,
					'ability'           => 'steadhand/update-post',
					'object_type'       => 'post',
					'object_id'         => $post,
					'result'            => $result,
					'undoable'          => 1,
					'snapshot_id'       => 1,
					'after_snapshot_id' => 2,
				)
			);

			$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::NOT_UNDOABLE );
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_a_post_edited_by_a_person_since_the_change_is_refused_and_keeps_the_edit(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'Edited outside Steadhand',
			)
		);
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_changed_since', self::CHANGED_SINCE );

		$this->assertSame( $before, $this->row( $post ), 'The person\'s edit stays.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_post_edited_directly_in_the_row_since_the_change_is_refused(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'excerpt' => 'After excerpt' ) );
		$this->direct_content( $post, 'Written straight to the row' );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_changed_since', self::CHANGED_SINCE );

		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_an_edit_that_lands_between_the_check_and_the_save_stops_the_save_and_writes_nothing(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry     = $this->changed( $post, array( 'title' => 'After' ) );
		$revisions = $this->revision_ids( $post );

		// Runs before the restore's own check, for this post's update only, once.
		$fired  = false;
		$inject = function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( &$fired, $post ): array {
			unset( $unsanitized_postarr );
			if ( ! $fired && $update && (int) ( $postarr['ID'] ?? 0 ) === $post ) {
				$fired = true;
				$this->direct_content( $post, 'Landed mid save' );
			}

			return $data;
		};
		add_filter( 'wp_insert_post_data', $inject, PHP_INT_MAX - 1, 4 );

		try {
			$result = $this->undo( $entry );
		} finally {
			remove_filter( 'wp_insert_post_data', $inject, PHP_INT_MAX - 1 );
		}

		$this->assertTrue( $fired, 'The change must have landed during the save.' );
		$this->assert_refused( $result, 'steadhand_refused_changed_since', self::CHANGED_SINCE );
		$this->assertSame( 'Landed mid save', get_post( $post )->post_content, 'The other write stays.' );
		$this->assertSame( 'After', get_post( $post )->post_title, 'The restore did not write the title.' );
		$this->assertSame( $revisions, $this->revision_ids( $post ), 'No revision is made by an aborted save.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );

		$this->assertFalse( doing_action( 'pre_post_update' ), 'The abort leaves no action in progress.' );
		$this->assertFalse( doing_filter( 'wp_insert_post_data' ), 'The abort leaves no filter in progress.' );
		$this->assertNotSame( 'pre_post_update', current_filter() );

		$out = $this->output_of(
			self::UPDATE,
			array(
				'id'    => $post,
				'title' => 'Normal after abort',
			)
		);
		$this->assertTrue( $out['changed'] );
		$this->assertSame( 'Normal after abort', get_post( $post )->post_title, 'A normal update-post right after the abort still works.' );
	}

	public function test_a_deleted_before_revision_is_refused_and_nothing_changes(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		wp_delete_post( (int) $this->stored( $entry )['snapshot_id'], true );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_deleted_after_revision_is_refused_and_nothing_changes(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		wp_delete_post( (int) $this->stored( $entry )['after_snapshot_id'], true );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_revision_of_another_post_is_refused_and_nothing_changes(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );

		$other     = $this->fixture( array( 'post_title' => 'Other' ) );
		$other_rev = wp_insert_post(
			array(
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $other,
				'post_title'   => 'Before title',
				'post_content' => self::paragraph( 'Before' ),
				'post_name'    => "$other-revision-v1",
			)
		);
		$this->set_entry( $entry, array( 'snapshot_id' => $other_rev ) );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_an_autosave_named_as_the_before_state_is_refused(): void {
		$post  = $this->fixture();
		$agent = $this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );

		wp_set_current_user( $this->admin_id() );
		$autosave = wp_create_post_autosave(
			array(
				'post_ID'      => $post,
				'post_type'    => 'post',
				'post_title'   => 'Before title',
				'post_content' => self::paragraph( 'Before' ),
				'post_excerpt' => 'Before excerpt',
			)
		);
		wp_set_current_user( $agent );
		$this->assertIsInt( $autosave );
		$this->set_entry( $entry, array( 'snapshot_id' => $autosave ) );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_post_row_as_the_before_state_is_refused(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );

		// A child page of the post: the same post parent, but not a revision. Its text differs, so only the type check refuses it.
		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $post,
				'post_title'  => 'Child text',
				'post_status' => 'publish',
			)
		);
		$this->set_entry( $entry, array( 'snapshot_id' => $child ) );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_trashed_post_is_refused_with_the_generic_text_and_nothing_changes(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		wp_trash_post( $post );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::NOT_FOUND );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_post_published_since_the_change_needs_steadhand_publish_for_the_undo(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'publish',
			)
		);
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::PUBLISH_NEEDED );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_post_published_since_the_change_is_undone_by_an_agent_that_holds_steadhand_publish(): void {
		$post = $this->fixture();
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'publish',
			)
		);

		$out = $this->output_of( self::UNDO_TOOL, array( 'id' => $entry ) );

		$this->assertTrue( $out['undone'] );
		$this->assertSame( 'Before title', get_post( $post )->post_title );
		$this->assertSame( 'publish', get_post( $post )->post_status );
	}

	public function test_a_caller_without_a_write_capability_is_refused_and_nothing_changes(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry  = $this->changed( $post, array( 'title' => 'After' ) );
		$stored = $this->stored( $entry );

		// The same entry, for an agent that holds neither write capability.
		$reader = $this->act_as_agent(
			array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::PUBLISH      => false,
			)
		);
		$own    = $this->insert_row(
			array(
				'user_id'           => $reader,
				'ability'           => 'steadhand/update-post',
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => 'ok',
				'undoable'          => 1,
				'snapshot_id'       => (int) $stored['snapshot_id'],
				'after_snapshot_id' => (int) $stored['after_snapshot_id'],
			)
		);
		$before = $this->row( $post );

		$result = $this->undo( $own );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 0, (int) $this->stored( $own )['undone_by'] );
	}

	public function test_a_schema_refusal_names_no_change_and_the_post_is_unchanged(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry  = $this->changed( $post, array( 'title' => 'After' ) );
		$before = $this->row( $post );

		$this->schema_refusal_text(
			$this->tool_result(
				self::UNDO_TOOL,
				array(
					'id'      => $entry,
					'post_id' => $post,
				)
			)
		);
		$this->schema_refusal_text( $this->tool_result( self::UNDO_TOOL, array( 'id' => 'abc' ) ) );
		$this->schema_refusal_text( $this->tool_result( self::UNDO_TOOL, array( 'id' => 0 ) ) );
		$this->schema_refusal_text( $this->tool_result( self::UNDO_TOOL, array() ) );

		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_an_undo_is_refused_when_the_change_log_table_is_not_ready(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry  = $this->changed( $post, array( 'title' => 'After' ) );
		$before = $this->row( $post );

		$this->make_table_look_missing();
		$result = $this->undo( $entry );

		$this->assertWPError( $result );
		$this->assertContains( $result->get_error_code(), array( 'steadhand_forbidden', 'steadhand_log_failed' ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_the_undo_is_subject_to_the_rate_limit(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 1 );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_guardrail_blocked' );

		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_the_undo_is_subject_to_the_edit_lock_on_its_post(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		$human = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_post_meta( $post, '_edit_lock', time() . ':' . $human );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_guardrail_blocked' );

		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_refusal_for_another_agent_is_logged_without_the_post_of_that_agent(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry    = $this->changed( $post, array( 'title' => 'Owned' ) );
		$intruder = $this->act_as_agent();

		$this->undo( $entry );

		$row = $this->latest_row( $intruder );
		$this->assertSame( 'blocked', $row['result'] );
		$this->assertSame( '', $row['object_type'], 'The intruder\'s row names no post.' );
		$this->assertSame( 0, (int) $row['object_id'] );
	}

	public function test_the_undo_of_a_change_is_recorded_once_and_its_entry_is_the_undo_s_log_row(): void {
		$post  = $this->fixture();
		$agent = $this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		$rows  = $this->count_rows( $agent );

		$this->output_of( self::UNDO_TOOL, array( 'id' => $entry ) );

		$this->assertSame( $rows + 1, $this->count_rows( $agent ), 'One row for the undo, and none for the restore.' );
	}

	public function test_restore_refuses_a_changed_post_without_writing_and_refuses_a_bad_text_array(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$this->changed( $post, array( 'title' => 'After' ) );
		$revisions = $this->revision_ids( $post );
		$before    = $this->row( $post );

		$outcome = $this->in_write(
			static function () use ( $post, $before, $revisions ): array {
				$stale = PostWriter::restore(
					$post,
					array(
						'post_title'   => 'Not what is stored',
						'post_content' => '',
						'post_excerpt' => '',
					),
					array(
						'post_title'   => 'Before title',
						'post_content' => '',
						'post_excerpt' => '',
					)
				);

				$threw = false;
				try {
					PostWriter::restore( $post, array( 'post_title' => 'x' ), array( 'post_title' => 'x' ) );
				} catch ( \InvalidArgumentException ) {
					$threw = true;
				}

				return array(
					'code'      => is_wp_error( $stale ) ? $stale->get_error_code() : 'not an error',
					'threw'     => $threw,
					'before'    => $before,
					'revisions' => $revisions,
				);
			}
		);

		$this->assertSame( 'steadhand_refused_changed_since', $outcome['code'] );
		$this->assertTrue( $outcome['threw'], 'A text array with other keys is an InvalidArgumentException.' );
		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( $revisions, $this->revision_ids( $post ) );
	}

	public function test_an_agent_that_recreates_the_after_text_cannot_undo_its_older_change(): void {
		$post = $this->fixture( array( 'post_title' => 'S0' ) );
		$this->act_as_agent();
		$first = $this->changed( $post, array( 'title' => 'S1' ) );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'S2',
			)
		);
		$this->changed( $post, array( 'title' => 'S1' ) );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $first ), 'steadhand_refused_changed_since', self::CHANGED_SINCE );

		$this->assertSame( $before, $this->row( $post ), 'The current text stays.' );
		$this->assertSame( 'S1', get_post( $post )->post_title );
		$this->assertSame( 0, (int) $this->stored( $first )['undone_by'] );
	}

	public function test_undoing_the_newest_of_two_agent_changes_works_and_then_the_older_one_is_refused(): void {
		$post = $this->fixture( array( 'post_title' => 'S0' ) );
		$this->act_as_agent();
		$first  = $this->changed( $post, array( 'title' => 'S1' ) );
		$second = $this->changed( $post, array( 'title' => 'S2' ) );

		$this->output_of( self::UNDO_TOOL, array( 'id' => $second ) );
		$this->assertSame( 'S1', get_post( $post )->post_title, 'The newest change is undone to the text before it.' );

		$before = $this->row( $post );
		$this->assert_refused( $this->undo( $first ), 'steadhand_refused_changed_since', self::CHANGED_SINCE );
		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 0, (int) $this->stored( $first )['undone_by'] );
	}

	public function test_an_after_snapshot_equal_to_the_before_snapshot_is_not_undoable(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		$this->set_entry( $entry, array( 'after_snapshot_id' => (int) $this->stored( $entry )['snapshot_id'] ) );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::NOT_UNDOABLE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_two_different_revisions_with_identical_text_are_refused(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );

		// A second revision whose text equals the after-state's: before and after then hold the same text.
		$copy = wp_insert_post(
			array(
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $post,
				'post_title'   => 'After',
				'post_content' => self::paragraph( 'Before' ),
				'post_excerpt' => 'Before excerpt',
				'post_name'    => "$post-revision-copy",
			)
		);
		$this->assertNotSame( (int) $this->stored( $entry )['after_snapshot_id'], $copy );
		$this->set_entry( $entry, array( 'snapshot_id' => $copy ) );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_with_revisions_turned_off_now_the_undo_is_refused_and_nothing_changes(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		add_filter( 'wp_revisions_to_keep', static fn(): int => 0 );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_with_one_revision_kept_now_the_undo_is_refused_and_nothing_changes(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		add_filter( 'wp_revisions_to_keep', static fn(): int => 1 );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_not_undoable', self::NO_SAVED_STATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_page_template_the_theme_no_longer_has_is_refused_before_the_write(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		update_post_meta( $post, '_wp_page_template', 'no-such-template.php' );
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_refused_invalid_template', self::TEMPLATE );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_refused_call_on_another_post_entry_names_no_object_in_the_log(): void {
		$post   = $this->fixture();
		$agent  = $this->act_as_agent();
		$entry  = $this->insert_row(
			array(
				'user_id'           => $agent,
				'ability'           => 'steadhand/set-post-terms',
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => 'ok',
				'undoable'          => 1,
				'snapshot_id'       => 1,
				'after_snapshot_id' => 2,
			)
		);
		$before = $this->row( $post );

		$this->assert_refused( $this->undo( $entry ), 'steadhand_forbidden', self::NOT_UNDOABLE );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'blocked', $row['result'] );
		$this->assertSame( '', $row['object_type'], 'The blocked row names no post.' );
		$this->assertSame( 0, (int) $row['object_id'] );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_restore_that_fails_before_the_write_is_update_failed_and_keeps_the_entry_unmarked(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry  = $this->changed( $post, array( 'title' => 'After' ) );
		$before = $this->row( $post );
		$this->fail_the_posts_update();

		$result = $this->undo( $entry );

		$this->assert_refused( $result, 'steadhand_update_failed' );
		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_restore_that_fails_after_another_write_is_update_partial(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );

		$done = false;
		add_filter(
			'query',
			static function ( string $sql ) use ( &$done, $post ): string {
				global $wpdb;

				if ( $done || ! str_starts_with( ltrim( $sql ), 'UPDATE `' . $wpdb->posts . '`' ) ) {
					return $sql;
				}

				// Another write lands first, then this UPDATE fails.
				$done = true;
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = %s WHERE ID = %d", 'Other write', $post ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: another request's write.

				return '';
			},
			PHP_INT_MAX - 2
		);

		$result = $this->undo( $entry );

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertSame( 'Other write', $this->row( $post )['post_content'], 'The other write stays in the row (read uncached).' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_restored_post_whose_mark_fails_says_so_and_stays_restored(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );
		$table = Schema::table();

		add_filter(
			'query',
			static function ( string $sql ) use ( $table ): string {
				return str_starts_with( ltrim( $sql ), 'UPDATE ' . $table . ' SET undone_by' ) ? '' : $sql;
			},
			PHP_INT_MAX - 2
		);

		$result = $this->undo( $entry );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_update_partial', $result->get_error_code() );
		$this->assertStringContainsString( 'could not record the undo', $result->get_error_message() );
		$this->assertSame( 'Before title', get_post( $post )->post_title, 'The post was restored.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'], 'The entry was not marked.' );
	}

	public function test_a_restore_that_reports_success_without_its_check_running_is_partial_not_success(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$entry = $this->changed( $post, array( 'title' => 'After' ) );

		// A listener at a priority before the restore's own removes the restore's pre_post_update action.
		$drop = static function ( array $data, array $postarr ) use ( $post ): array {
			if ( (int) ( $postarr['ID'] ?? 0 ) === $post ) {
				remove_all_actions( 'pre_post_update', PHP_INT_MAX );
			}

			return $data;
		};
		add_filter( 'wp_insert_post_data', $drop, PHP_INT_MAX - 1, 2 );

		try {
			$result = $this->undo( $entry );
		} finally {
			remove_filter( 'wp_insert_post_data', $drop, PHP_INT_MAX - 1 );
		}

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}
}
