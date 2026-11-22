<?php
/**
 * MF-2: an update never rewrites a field the call did not send, and content_modified_on_save covers all three fields.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * A post an administrator wrote with an iframe, a script and a style. An agent's save runs KSES over every stored field, so
 * the markup would be removed. The fixtures are written as the administrator, because a post written with no user already
 * passes through KSES on insert.
 */
final class UnsafeRewriteTest extends PostWriteTestCase {

	private const DIRTY = '<p>Hi</p><iframe src="https://www.youtube.com/embed/x"></iframe><script>var a=1;</script><style>p{color:red}</style>';

	/**
	 * Creates a draft owned by the administrator, with the given fields. Returns its ID.
	 *
	 * @param array<string, mixed> $fields Post fields.
	 */
	private function admin_draft( array $fields ): int {
		wp_set_current_user( $this->admin_id() );
		$post = $this->admin_post(
			array_merge(
				array(
					'post_status'  => 'draft',
					'post_title'   => 'T',
					'post_content' => self::DIRTY,
				),
				$fields
			)
		);
		wp_set_current_user( 0 );

		return $post;
	}

	public function test_probe_b_a_title_only_update_of_an_administrators_iframe_post_is_refused_and_changes_nothing(): void {
		$post = $this->admin_draft( array() );
		$this->assertStringContainsString( '<iframe', get_post( $post )->post_content, 'The fixture keeps the administrator markup.' );
		$agent     = $this->act_as_agent();
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
		$this->assertStringContainsString( 'content', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $post ), 'The post row must be byte-identical.' );
		$this->assertSame( $revisions, $this->revision_ids( $post ), 'No revision may be made for a refused call.' );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'error', $row['result'] );
		$this->assertSame( $result->get_error_message(), $row['message'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	public function test_probe_v_sending_the_stored_content_unchanged_is_refused_too_because_the_save_would_still_rewrite_it(): void {
		$post = $this->admin_draft( array() );
		$this->act_as_agent();
		$before = $this->row( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => self::DIRTY,
				'status'  => 'pending',
			)
		);

		$this->assertSame( 'steadhand_refused_unsafe_rewrite', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_refusal_also_applies_when_the_call_would_change_nothing(): void {
		$post = $this->admin_draft( array() );
		$this->act_as_agent();
		$before = $this->row( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'T',
			)
		);

		$this->assertSame( 'steadhand_refused_unsafe_rewrite', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_full_content_replacement_of_such_a_post_is_allowed_and_content_modified_on_save_is_true(): void {
		$post = $this->admin_draft( array() );
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
	}

	public function test_an_unsent_excerpt_with_a_script_is_refused_when_only_the_content_is_sent(): void {
		$post = $this->admin_draft(
			array(
				'post_content' => '<p>clean</p>',
				'post_excerpt' => '<script>e</script>ex',
			)
		);
		$this->act_as_agent();
		$before = $this->row( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => '<p>new</p>',
			)
		);

		$this->assertSame( 'steadhand_refused_unsafe_rewrite', $this->refusal_code( $result ) );
		$this->assertStringContainsString( 'excerpt', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_clean_post_is_unaffected_by_the_check(): void {
		$post = $this->admin_draft( array( 'post_content' => '<p>clean</p>' ) );
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
		$post = $this->admin_draft( array( 'post_content' => '<p>clean</p>' ) );
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
		$post = $this->admin_draft( array( 'post_content' => '<p>clean</p>' ) );
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
}
