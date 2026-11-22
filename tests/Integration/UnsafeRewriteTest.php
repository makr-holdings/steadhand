<?php
/**
 * An update saves only the fields it sends through KSES. The fields it does not send are saved as they are stored.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\PostWriter;

/**
 * A post an administrator wrote with markup that KSES would remove for an agent. The fixtures are written as the
 * administrator, so they are stored as written. Covers the update path (PostWriter::update() and would_alter_unsent()) and
 * the insert path, which filters everything.
 */
final class UnsafeRewriteTest extends PostWriteTestCase {

	/** Content an administrator wrote: an iframe, a script, a style, a void tag, bare and encoded ampersands, quotes, inline style. */
	private const RICH = "<p class='note' style=\"color: red;\">Tom & Jerry &amp; Q&A <img src=\"https://example.org/a.jpg\" alt=\"\" /></p><hr/><iframe src=\"https://www.youtube.com/embed/x\"></iframe><script>var a=1;</script><style>p{color:red}</style>";

	/** Excerpt an administrator wrote, with a script in it. */
	private const RICH_EXCERPT = '<script>e</script>ex & <b>bold</b>';

	/** Filtered content an administrator wrote. */
	private const RICH_FILTERED = '<iframe src="https://a.example/"></iframe>md & <script>x()</script>';

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
		$post = $this->admin_post(
			array_merge(
				array(
					'post_status'  => 'draft',
					'post_title'   => 'T',
					'post_content' => self::RICH,
					'post_excerpt' => self::RICH_EXCERPT,
				),
				$fields
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
		$this->assertStringNotContainsString( '<script', get_post( $post )->post_title, 'A script the agent sent in the title is stripped.' );
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

	public function test_a_third_party_filter_that_would_rewrite_an_unsent_field_refuses_the_call_before_any_write(): void {
		$post = $this->rich_post( array( 'post_content' => '<p>clean</p>' ) );
		add_filter(
			'content_save_pre',
			static fn( string $content ): string => $content . '<!-- added -->'
		);
		$this->act_as_agent();
		$before    = $this->row( $post );
		$revisions = $this->revision_ids( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'New title',
			)
		);

		$this->assertSame( 'steadhand_refused_unsafe_rewrite', $this->refusal_code( $result ) );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'Saving this post would change its content, which this call did not send, because of a filter on this site. Nothing was changed.', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( $revisions, $this->revision_ids( $post ) );
	}

	public function test_the_refusal_names_every_field_a_filter_would_change_in_the_right_words(): void {
		$post = $this->rich_post( array( 'post_content' => '<p>clean</p>' ) );
		foreach ( array( 'title_save_pre', 'excerpt_save_pre', 'content_save_pre' ) as $hook ) {
			add_filter( $hook, static fn( string $value ): string => $value . ' ' );
		}
		$this->act_as_agent();

		$two = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $post,
				'status' => 'pending',
			)
		);
		$this->assertSame( 'steadhand_refused_unsafe_rewrite', $this->refusal_code( $two ) );
		$this->assertStringContainsString( 'change its title, content and excerpt, which', $two->get_error_message() );
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
					'post_content' => $html,
					'post_excerpt' => $html,
				)
			);
		}
		$this->act_as_agent();

		foreach ( $ids as $id ) {
			$this->assertSame( array(), PostWriter::would_alter_unsent( $id, array( 'post_title' => 'x' ) ) );
		}
	}
}
