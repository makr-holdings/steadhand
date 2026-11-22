<?php
/**
 * An update saves only the fields it sends through KSES. The fields it does not send are saved as they are stored.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\PostWriter;
use WP_Hook;

/**
 * A post an administrator wrote with markup that KSES would remove for an agent. The fixtures are written as the
 * administrator, so they are stored as written. Covers the update path (PostWriter::update() and the post-write check in
 * UpdatePost) and the insert path, which filters everything.
 */
final class UnsafeRewriteTest extends PostWriteTestCase {

	/**
	 * Content an administrator wrote: an iframe, a script, a style, a void tag, bare and encoded ampersands, quotes, single
	 * quotes, inline style, and backslashes next to quotes.
	 */
	private const RICH = "<p class='note' style=\"color: red;\">Tom & Jerry &amp; Q&A <img src=\"https://example.org/a.jpg\" alt=\"\" /></p><hr/><iframe src=\"https://www.youtube.com/embed/x\"></iframe><script>var a=1;</script><style>p{color:red}</style><p>C:\\dir\\new \"q\" \\\\ end</p>";

	/** Excerpt an administrator wrote, with a script, an ampersand, and a backslash next to quotes. */
	private const RICH_EXCERPT = '<script>e</script>ex & <b>bold</b> \\ "q"';

	/** Filtered content an administrator wrote, with an iframe, an ampersand and a backslash next to an apostrophe. */
	private const RICH_FILTERED = '<iframe src="https://a.example/"></iframe>md & <script>x()</script> \\ it\'s';

	/** The four post-field save filters, and the Global Styles filter core adds with them. */
	private const SAVE_FILTERS = array(
		array( 'title_save_pre', 'wp_filter_kses' ),
		array( 'content_save_pre', 'wp_filter_post_kses' ),
		array( 'excerpt_save_pre', 'wp_filter_post_kses' ),
		array( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
		array( 'content_save_pre', 'wp_filter_global_styles_post' ),
		array( 'content_filtered_save_pre', 'wp_filter_global_styles_post' ),
	);

	/**
	 * Creates a draft owned by the administrator with the rich fields, unless overridden. Returns its ID.
	 *
	 * @param array<string, mixed> $fields Post fields that override the defaults.
	 */
	private function rich_post( array $fields = array() ): int {
		wp_set_current_user( $this->admin_id() );
		// wp_insert_post() unslashes what it is given, so the values are slashed first: the stored bytes are the ones above.
		$post = $this->admin_post(
			array_map(
				'wp_slash',
				array_merge(
					array(
						'post_status'  => 'draft',
						'post_title'   => 'T',
						'post_content' => self::RICH,
						'post_excerpt' => self::RICH_EXCERPT,
					),
					$fields
				)
			)
		);
		$this->set_filtered_content( $post, self::RICH_FILTERED );
		wp_set_current_user( 0 );

		return $post;
	}

	/**
	 * Sets post_content_filtered, which WordPress has no API to set.
	 *
	 * @param int    $post Post ID.
	 * @param string $text Text.
	 */
	private function set_filtered_content( int $post, string $text ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_content_filtered' => $text ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture: the field has no API.
		clean_post_cache( $post );
	}

	/**
	 * The stored value of one column, read straight from the table.
	 *
	 * @param int    $post   Post ID.
	 * @param string $column Column name, such as post_content_filtered.
	 */
	private function raw( int $post, string $column ): string {
		global $wpdb;

		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the stored bytes.
			$wpdb->prepare( "SELECT {$column} FROM {$wpdb->posts} WHERE ID = %d", $post ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column names are fixed in this file.
		);
	}

	/**
	 * The priority each save filter has now, or false. Captures what the restore check compares.
	 *
	 * @return array<string, int|false>
	 */
	private function save_filter_state(): array {
		$state = array();
		foreach ( self::SAVE_FILTERS as $pair ) {
			$state[ $pair[0] . ' ' . $pair[1] ] = has_filter( $pair[0], $pair[1] );
		}

		return $state;
	}

	/**
	 * The number of callbacks on a hook, at every priority. Used to show that a write left none of its own behind.
	 *
	 * @param string $hook Hook name.
	 */
	private function callback_count( string $hook ): int {
		$registered = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $registered instanceof WP_Hook ) {
			return 0;
		}

		$count = 0;
		foreach ( $registered->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}

	public function test_a_title_only_update_of_an_administrators_post_succeeds_and_leaves_the_other_fields_byte_identical(): void {
		$post = $this->rich_post();
		$this->assertStringContainsString( '<iframe', get_post( $post )->post_content, 'The fixture keeps the administrator markup.' );
		$this->act_as_agent();
		$before = $this->row( $post );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New title',
			)
		);

		$this->assertIsArray( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertFalse( $out['content_modified_on_save'], 'No field the call sent was changed by the save.' );
		$this->assertSame( 'New title', get_post( $post )->post_title );
		$this->assertSame( self::RICH, $this->raw( $post, 'post_content' ), 'The content is byte-identical.' );
		$this->assertSame( self::RICH_EXCERPT, $this->raw( $post, 'post_excerpt' ), 'The excerpt is byte-identical.' );
		$this->assertSame( self::RICH_FILTERED, $this->raw( $post, 'post_content_filtered' ), 'The filtered content is byte-identical.' );
		$this->assertSame( $before['post_content'], $this->raw( $post, 'post_content' ) );
	}

	public function test_backslashes_and_quotes_in_the_unsent_fields_round_trip_byte_for_byte(): void {
		$content  = 'C:\\path\\to "file" \\\\ \\n \\\' end';
		$excerpt  = "\\'quoted\\' \\\\\\ \"x\" \\\\";
		$filtered = 'a\\b "c" \\d';
		$post     = $this->rich_post(
			array(
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			)
		);
		$this->set_filtered_content( $post, $filtered );
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'Backslashes',
			)
		);

		$this->assertIsArray( $out );
		$this->assertFalse( $out['content_modified_on_save'] );
		$this->assertSame( $content, $this->raw( $post, 'post_content' ) );
		$this->assertSame( $excerpt, $this->raw( $post, 'post_excerpt' ) );
		$this->assertSame( $filtered, $this->raw( $post, 'post_content_filtered' ) );
	}

	public function test_the_fields_that_are_sent_are_filtered_and_the_others_are_not_in_the_same_call(): void {
		$post = $this->rich_post();
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'Hi<script>x()</script>There',
			)
		);

		$this->assertIsArray( $out );
		$this->assertTrue( $out['content_modified_on_save'], 'The title the agent sent was changed by the save.' );
		$this->assertSame( 'Hix()There', get_post( $post )->post_title, 'The script the agent sent in the title is removed, and its text kept.' );
		$this->assertSame( self::RICH, $this->raw( $post, 'post_content' ) );
		$this->assertSame( self::RICH_EXCERPT, $this->raw( $post, 'post_excerpt' ) );
	}

	public function test_sending_the_stored_content_unchanged_with_a_status_change_keeps_the_content_byte_identical(): void {
		$post = $this->rich_post();
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => self::RICH,
				'status'  => 'pending',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'pending', get_post( $post )->post_status );
		$this->assertSame( self::RICH, $this->raw( $post, 'post_content' ) );
		$this->assertSame( self::RICH_EXCERPT, $this->raw( $post, 'post_excerpt' ) );
	}

	public function test_a_call_that_names_only_an_unchanged_title_succeeds_and_changes_nothing(): void {
		$post = $this->rich_post( array( 'post_title' => 'T' ) );
		$this->act_as_agent();
		$before    = $this->row( $post );
		$revisions = $this->revision_ids( $post );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'T',
			)
		);

		$this->assertIsArray( $out );
		$this->assertFalse( $out['changed'] );
		$this->assertSame( $before, $this->row( $post ), 'The post row is byte-identical.' );
		$this->assertSame( $revisions, $this->revision_ids( $post ), 'No revision is made for a call that changes nothing.' );
	}

	public function test_a_full_content_replacement_of_such_a_post_is_filtered_and_content_modified_on_save_is_true(): void {
		$post = $this->rich_post();
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => '<p>Mine</p><script>x()</script>',
			)
		);

		$this->assertIsArray( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertTrue( $out['content_modified_on_save'], 'KSES removed the script the agent sent.' );
		$this->assertSame( '<p>Mine</p>x()', get_post( $post )->post_content, 'KSES drops the script tags and keeps their text.' );
		$this->assertSame( self::RICH_EXCERPT, $this->raw( $post, 'post_excerpt' ), 'The unsent excerpt is byte-identical.' );
		$this->assertSame( self::RICH_FILTERED, $this->raw( $post, 'post_content_filtered' ), 'The unsent filtered content is byte-identical.' );
	}

	public function test_an_unsent_excerpt_with_a_script_is_kept_byte_identical_when_only_the_content_is_sent(): void {
		$post = $this->rich_post(
			array(
				'post_content' => '<p>clean</p>',
				'post_excerpt' => '<script>e</script>ex',
			)
		);
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => '<p>new</p>',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( '<p>new</p>', get_post( $post )->post_content );
		$this->assertSame( '<script>e</script>ex', $this->raw( $post, 'post_excerpt' ) );
		$this->assertFalse( $out['content_modified_on_save'] );
	}

	public function test_a_clean_post_is_unaffected_by_the_change(): void {
		$post = $this->rich_post( array( 'post_content' => '<p>clean</p>' ) );
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'Clean title',
			)
		);

		$this->assertIsArray( $out );
		$this->assertTrue( $out['changed'] );
		$this->assertFalse( $out['content_modified_on_save'] );
		$this->assertSame( '<p>clean</p>', get_post( $post )->post_content );
		$this->assertSame( 'Clean title', get_post( $post )->post_title );
	}

	public function test_content_modified_on_save_covers_the_title_when_the_title_is_sent_with_markup_the_save_removes(): void {
		$post = $this->rich_post( array( 'post_content' => '<p>clean</p>' ) );
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'Hello <script>x()</script>World',
			)
		);

		$this->assertIsArray( $out );
		$this->assertTrue( $out['content_modified_on_save'], 'The title is one of the three fields that is compared.' );
	}

	public function test_content_modified_on_save_covers_the_excerpt_when_it_is_sent_with_markup_the_save_removes(): void {
		$post = $this->rich_post( array( 'post_content' => '<p>clean</p>' ) );
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'excerpt' => 'Short <script>x()</script>',
			)
		);

		$this->assertIsArray( $out );
		$this->assertTrue( $out['content_modified_on_save'] );
	}

	public function test_an_update_that_sends_content_cannot_smuggle_markup_through_the_title_or_excerpt_it_also_sends(): void {
		$post = $this->rich_post();
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'title'   => '<iframe src="https://evil.example/"></iframe>Title',
				'content' => '<p>Body</p>',
				'excerpt' => '<script>steal()</script>Short',
			)
		);

		$this->assertIsArray( $out );
		$this->assertStringNotContainsString( '<iframe', get_post( $post )->post_title );
		$this->assertStringNotContainsString( '<script', $this->raw( $post, 'post_excerpt' ) );
		$this->assertStringNotContainsString( '<script', get_post( $post )->post_content );
	}

	public function test_insert_filters_every_field_the_agent_sends(): void {
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/create-post',
			array(
				'title'   => 'Hi<script>t()</script>',
				'content' => '<p>Body</p><iframe src="https://evil.example/"></iframe><script>c()</script>',
				'excerpt' => '<script>e()</script>Short',
			)
		);

		$this->assertIsArray( $out );
		$this->assertStringNotContainsString( '<script', get_post( $out['id'] )->post_title );
		$this->assertStringNotContainsString( '<iframe', get_post( $out['id'] )->post_content );
		$this->assertStringNotContainsString( '<script', get_post( $out['id'] )->post_content );
		$this->assertStringNotContainsString( '<script', get_post( $out['id'] )->post_excerpt );
	}

	public function test_the_save_filters_are_in_the_state_they_were_before_the_call_present_and_absent_alike(): void {
		$post = $this->rich_post();
		$this->act_as_agent();
		foreach ( self::SAVE_FILTERS as $pair ) {
			remove_filter( $pair[0], $pair[1] );
		}
		add_filter( 'content_save_pre', 'wp_filter_post_kses' );
		add_filter( 'content_filtered_save_pre', 'wp_filter_global_styles_post', 9 );
		$before = $this->save_filter_state();

		$this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New',
			)
		);

		$this->assertSame( $before, $this->save_filter_state(), 'Each save filter is present at the same priority, or absent, as before.' );
	}

	public function test_the_save_filters_are_restored_when_the_write_throws(): void {
		$post = $this->rich_post();
		$this->act_as_agent();
		foreach ( self::SAVE_FILTERS as $pair ) {
			remove_filter( $pair[0], $pair[1] );
		}
		add_filter( 'content_save_pre', 'wp_filter_post_kses' );
		$before = $this->save_filter_state();
		add_action(
			'save_post',
			static function ( int $id ) use ( $post ): void {
				if ( $id === $post ) {
					throw new \RuntimeException( 'listener failed' );
				}
			},
			999
		);

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New',
			)
		);

		$this->assertWPError( $result, 'The throw is reported.' );
		$this->assertSame( $before, $this->save_filter_state(), 'The filters are restored after a throw.' );
	}

	public function test_a_third_party_save_filter_that_rewrites_an_unsent_field_does_not_refuse_the_call_or_change_the_field(): void {
		$post = $this->rich_post( array( 'post_content' => '<p>clean</p>' ) );
		add_filter(
			'content_save_pre',
			static fn( string $content ): string => $content . '<!-- added -->'
		);
		add_filter(
			'excerpt_save_pre',
			static fn( string $excerpt ): string => $excerpt . ' changed'
		);
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New title',
			)
		);

		$this->assertIsArray( $out, 'A filter on the unsent fields is no longer a refusal.' );
		$this->assertSame( 'New title', get_post( $post )->post_title );
		$this->assertSame( '<p>clean</p>', $this->raw( $post, 'post_content' ), 'The unsent content is byte-identical, whatever the filter does.' );
		$this->assertSame( self::RICH_EXCERPT, $this->raw( $post, 'post_excerpt' ), 'The unsent excerpt is byte-identical, whatever the filter does.' );
		$this->assertFalse( $out['content_modified_on_save'] );
	}

	public function test_core_alone_reports_nothing_for_any_stored_value(): void {
		$samples = array(
			'<p>Tom & Jerry</p>',
			'<p>a < b and c > d</p>',
			'<p>Hi 😀 🎉</p>',
			"<p>a&nbsp;b c\u{00A0}d</p>",
			self::RICH,
			'<img src="https://x/a.jpg" alt="" />',
			"<p class='x'>y</p>",
			'<p style="color: red;">y</p>',
			'<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>',
			'<iframe src="https://www.youtube.com/embed/x"></iframe>',
			'<script>var a=1;</script>',
			'<style>p{color:red}</style>',
		);

		wp_set_current_user( $this->admin_id() );
		$ids = array();
		foreach ( $samples as $html ) {
			$ids[] = $this->admin_post(
				array(
					'post_status'  => 'draft',
					'post_content' => wp_slash( $html ),
					'post_excerpt' => wp_slash( $html ),
				)
			);
		}
		$this->act_as_agent();

		foreach ( $ids as $index => $id ) {
			$out = $this->execute_ability(
				'steadhand/update-post',
				array(
					'id'    => $id,
					'title' => 'Title only',
				)
			);

			$this->assertIsArray( $out, "Sample $index: a title-only update succeeds." );
			$this->assertSame( $samples[ $index ], $this->raw( $id, 'post_content' ), "Sample $index: the content is byte-identical." );
			$this->assertSame( $samples[ $index ], $this->raw( $id, 'post_excerpt' ), "Sample $index: the excerpt is byte-identical." );
		}
	}

	public function test_a_row_changed_by_another_writer_after_the_restore_is_reported_as_partial_and_never_as_a_success(): void {
		$post = $this->rich_post( array( 'post_content' => '<p>clean</p>' ) );
		$this->act_as_agent();
		add_action(
			'save_post',
			static function ( int $id ) use ( $post ): void {
				global $wpdb;

				if ( $id === $post ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>changed under us</p>' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulates code that writes the row directly after the restore.
				}
			},
			999
		);

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New title',
			)
		);

		$this->assertSame( 'steadhand_update_partial', $this->refusal_code( $result ) );
		$this->assertSame( 'The post was changed, but the save did not finish as expected, so the change was at least partly applied. Check the post before you continue.', $result->get_error_message() );
		$this->assertSame( '<p>changed under us</p>', $this->raw( $post, 'post_content' ), 'The row is left as the other writer made it.' );
	}

	public function test_no_wp_insert_post_data_callback_is_left_behind_after_a_successful_update(): void {
		$post = $this->rich_post();
		$this->act_as_agent();
		$before = $this->callback_count( 'wp_insert_post_data' );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( $before, $this->callback_count( 'wp_insert_post_data' ), 'The restore filter is removed after a success.' );
	}

	public function test_no_wp_insert_post_data_callback_is_left_behind_after_a_wp_error(): void {
		$post = $this->rich_post( array( 'post_content' => '<p>clean</p>' ) );
		$this->act_as_agent();
		add_action(
			'save_post',
			static function ( int $id ) use ( $post ): void {
				global $wpdb;

				if ( $id === $post ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>changed</p>' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Makes the write end in a WP_Error.
				}
			},
			999
		);
		$before = $this->callback_count( 'wp_insert_post_data' );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( $before, $this->callback_count( 'wp_insert_post_data' ), 'The restore filter is removed after a WP_Error.' );
	}

	public function test_no_wp_insert_post_data_callback_is_left_behind_after_a_throw(): void {
		$post = $this->rich_post();
		$this->act_as_agent();
		add_action(
			'save_post',
			static function ( int $id ) use ( $post ): void {
				if ( $id === $post ) {
					throw new \RuntimeException( 'listener failed' );
				}
			},
			999
		);
		$before = $this->callback_count( 'wp_insert_post_data' );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( $before, $this->callback_count( 'wp_insert_post_data' ), 'The restore filter is removed after a throw.' );
	}

	public function test_a_nested_write_of_another_post_during_an_update_is_not_given_this_posts_restored_values(): void {
		$target = $this->rich_post();
		$other  = $this->admin_post(
			array(
				'post_content' => '<p>other</p>',
				'post_excerpt' => 'Other excerpt',
			)
		);
		$this->act_as_agent();
		$triggered = false;
		// Runs inside the target's own sanitize step, which is before core's wp_insert_post_data filter, so the nested write of
		// the other post happens while the target's restore is still armed.
		add_filter(
			'title_save_pre',
			static function ( string $value ) use ( $other, &$triggered ): string {
				if ( ! $triggered ) {
					$triggered = true;
					PostWriter::update( $other, array( 'post_content' => '<p>other</p><script>z()</script>' ) );
				}

				return $value;
			},
			1
		);

		$out = $this->in_write(
			static fn(): int|\WP_Error => PostWriter::update( $target, array( 'post_title' => 'Target' ) )
		);

		$this->assertSame( $target, $out );
		$this->assertSame( '<p>other</p>z()', $this->raw( $other, 'post_content' ), 'The other post content is filtered by its own write, not restored to the target values.' );
		$this->assertSame( 'Other excerpt', $this->raw( $other, 'post_excerpt' ) );
		$this->assertSame( self::RICH, $this->raw( $target, 'post_content' ), 'The target content is still byte-identical.' );
	}
}
