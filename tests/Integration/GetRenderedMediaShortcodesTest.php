<?php
/**
 * The steadhand/get-rendered render guard for core's [gallery] and [playlist] shortcodes.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Covers the pre_do_shortcode_tag guard (SPEC §5, §14.1): core's gallery and playlist shortcodes must not render media
 * that steadhand/get-media refuses, or the children of a post that steadhand/get-post refuses. Each negative test
 * names an attachment or post the agent may not read, and shows that get-media refuses the attachment too.
 */
final class GetRenderedMediaShortcodesTest extends MediaToolTestCase {

	private const TOOL_NAME  = 'steadhand-get-rendered';
	private const MEDIA_TOOL = 'steadhand-get-media';

	/**
	 * Renders content in a new published post, as the agent, and returns the HTML.
	 *
	 * @param string $content Post content.
	 */
	private function rendered( string $content ): string {
		$post = $this->admin_post( array( 'post_content' => $content ) );

		return $this->output( self::TOOL_NAME, array( 'id' => $post ) )['html'];
	}

	/**
	 * An image with a caption that is easy to search for.
	 *
	 * @param string $caption Caption (post excerpt).
	 * @param int    $parent_id Parent post ID, or 0.
	 */
	private function captioned_image( string $caption, int $parent_id = 0 ): int {
		return $this->image( $parent_id, array( 'post_excerpt' => $caption ) );
	}

	/**
	 * An audio attachment whose file and title can be searched for.
	 *
	 * @param string $file   File name, relative to the uploads folder.
	 * @param string $title  Title.
	 * @param int    $parent_id Parent post ID, or 0.
	 */
	private function audio_track( string $file, string $title, int $parent_id = 0 ): int {
		$id = $this->plain( 'audio/mpeg', $parent_id, array( 'post_title' => $title ) );
		update_attached_file( $id, $file );

		return $id;
	}

	/**
	 * A post of the non-allowed type, with the given status.
	 *
	 * @param string $status Post status.
	 */
	private function secret_post( string $status ): int {
		return $this->admin_post(
			array(
				'post_type'   => self::SECRET_TYPE,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Whether the HTML contains an item for this attachment, as core's gallery prints it: each item links to the
	 * attachment page (get_attachment_link()), which names the ID.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $html Rendered HTML.
	 */
	private function shows_image( int $id, string $html ): bool {
		return 1 === preg_match( '/' . preg_quote( get_attachment_link( $id ), '/' ) . '(?!\d)/', $html );
	}

	/**
	 * Asserts that an image of this attachment does not render: no file name, no image element, no attachment link.
	 *
	 * @param int    $id      Attachment ID.
	 * @param string $html    Rendered HTML.
	 * @param string $context Where, for the failure message.
	 */
	private function assert_image_hidden( int $id, string $html, string $context ): void {
		$this->assertStringNotContainsString( wp_basename( (string) wp_get_attachment_url( $id ) ), $html, "$context: the file name must not render." );
		$this->assertFalse( $this->shows_image( $id, $html ), "$context: the image and its link must not render." );
	}

	/**
	 * Asserts that steadhand/get-media refuses an attachment with the standard text.
	 *
	 * @param int $id Attachment ID.
	 */
	private function assert_get_media_refuses( int $id ): void {
		$this->assertSame( self::NOT_FOUND, $this->refusal_text( $this->tool_result( self::MEDIA_TOOL, array( 'id' => $id ) ) ), 'get-media must refuse the same attachment.' );
	}

	/**
	 * Number of callbacks on pre_do_shortcode_tag.
	 */
	private function shortcode_filter_count(): int {
		return isset( $GLOBALS['wp_filter']['pre_do_shortcode_tag'] ) ? array_sum( array_map( 'count', $GLOBALS['wp_filter']['pre_do_shortcode_tag']->callbacks ) ) : 0;
	}

	public function test_gallery_ids_naming_an_attachment_under_a_private_post_of_a_secret_type_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'SECRET-CAPTION-IDS', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[gallery ids="' . $image . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery ids' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-IDS', $html, 'gallery ids: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	public function test_gallery_include_naming_an_attachment_under_a_private_post_of_a_secret_type_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'SECRET-CAPTION-INCLUDE', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[gallery include="' . $image . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery include' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-INCLUDE', $html, 'gallery include: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	public function test_gallery_ids_naming_an_attachment_under_a_trashed_post_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'TRASHED-CAPTION-IDS', $this->admin_post( array( 'post_status' => 'trash' ) ) );

		$html = $this->rendered( '[gallery ids="' . $image . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery ids, trashed parent' );
		$this->assertStringNotContainsString( 'TRASHED-CAPTION-IDS', $html, 'gallery ids, trashed parent: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	public function test_gallery_include_naming_an_attachment_under_a_trashed_post_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'TRASHED-CAPTION-INCLUDE', $this->admin_post( array( 'post_status' => 'trash' ) ) );

		$html = $this->rendered( '[gallery include="' . $image . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery include, trashed parent' );
		$this->assertStringNotContainsString( 'TRASHED-CAPTION-INCLUDE', $html, 'gallery include, trashed parent: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	public function test_a_mixed_gallery_list_is_refused_whole(): void {
		$this->act_as_agent();
		$readable = $this->captioned_image( 'READABLE-CAPTION-MIXED' );
		$secret   = $this->captioned_image( 'SECRET-CAPTION-MIXED', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[gallery ids="' . $readable . ',' . $secret . '"]' );

		$this->assertFalse( $this->shows_image( $readable, $html ), 'The readable image must not render when the list holds a refused one.' );
		$this->assertFalse( $this->shows_image( $secret, $html ), 'The refused image must not render.' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-MIXED', $html, 'The refused caption must not render.' );
		$this->assertStringNotContainsString( 'READABLE-CAPTION-MIXED', $html, 'The readable caption must not render when the list is refused.' );
	}

	/**
	 * Core or MetaCaps already refuses this case in this WordPress version: the test passes without the shortcode guard,
	 * so it is kept as a regression test.
	 */
	public function test_an_unparsable_gallery_include_renders_nothing(): void {
		$this->act_as_agent();
		$readable = $this->captioned_image( 'READABLE-CAPTION-JUNK' );

		// Core would fetch the attachments for this value in some way this check does not reproduce, so nothing renders.
		$html = $this->rendered( '[gallery include="not-an-id"]' );

		$this->assertFalse( $this->shows_image( $readable, $html ), 'An include list with no IDs must render nothing.' );
	}

	public function test_gallery_id_of_a_draft_of_a_secret_type_renders_none_of_its_children(): void {
		$this->act_as_agent();
		$parent = $this->secret_post( 'draft' );
		$image  = $this->captioned_image( 'SECRET-CAPTION-DRAFT-PARENT', $parent );

		$html = $this->rendered( '[gallery id="' . $parent . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery id, draft secret parent' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-DRAFT-PARENT', $html, 'The child caption must not render.' );
	}

	/**
	 * Core or MetaCaps already refuses this case in this WordPress version: the test passes without the shortcode guard,
	 * so it is kept as a regression test.
	 */
	public function test_gallery_id_of_a_trashed_post_renders_none_of_its_children(): void {
		$this->act_as_agent();
		$parent = $this->admin_post( array( 'post_status' => 'trash' ) );
		$image  = $this->captioned_image( 'TRASHED-CAPTION-ID-PARENT', $parent );

		$html = $this->rendered( '[gallery id="' . $parent . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery id, trashed parent' );
		$this->assertStringNotContainsString( 'TRASHED-CAPTION-ID-PARENT', $html, 'The child caption must not render.' );
	}

	public function test_gallery_id_zero_renders_no_unattached_image(): void {
		$this->act_as_agent();
		$unattached = $this->captioned_image( 'PUBLIC-CAPTION-ZERO' );

		$html = $this->rendered( '[gallery id="0"]' );

		$this->assertFalse( $this->shows_image( $unattached, $html ), 'An id of 0 must render nothing.' );
	}

	public function test_playlist_ids_naming_a_track_under_a_private_post_of_a_secret_type_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$track = $this->audio_track( 'steadhand-secret-ids.mp3', 'SECRET-TITLE-PLAYLIST-IDS', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[playlist ids="' . $track . '"]' );

		$this->assertStringNotContainsString( 'steadhand-secret-ids.mp3', $html, 'The track file must not render.' );
		$this->assertStringNotContainsString( 'SECRET-TITLE-PLAYLIST-IDS', $html, 'The track title must not render.' );
		$this->assert_get_media_refuses( $track );
	}

	public function test_playlist_include_naming_a_track_under_a_private_post_of_a_secret_type_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$track = $this->audio_track( 'steadhand-secret-include.mp3', 'SECRET-TITLE-PLAYLIST-INCLUDE', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[playlist include="' . $track . '"]' );

		$this->assertStringNotContainsString( 'steadhand-secret-include.mp3', $html, 'The track file must not render.' );
		$this->assertStringNotContainsString( 'SECRET-TITLE-PLAYLIST-INCLUDE', $html, 'The track title must not render.' );
		$this->assert_get_media_refuses( $track );
	}

	public function test_playlist_id_of_a_draft_of_a_secret_type_renders_none_of_its_children(): void {
		$this->act_as_agent();
		$parent = $this->secret_post( 'draft' );
		$this->audio_track( 'steadhand-secret-draft-parent.mp3', 'SECRET-TITLE-PLAYLIST-DRAFT', $parent );

		$html = $this->rendered( '[playlist id="' . $parent . '"]' );

		$this->assertStringNotContainsString( 'steadhand-secret-draft-parent.mp3', $html, 'The child track must not render.' );
		$this->assertStringNotContainsString( 'SECRET-TITLE-PLAYLIST-DRAFT', $html, 'The child title must not render.' );
	}

	/**
	 * Core or MetaCaps already refuses this case in this WordPress version: the test passes without the shortcode guard,
	 * so it is kept as a regression test.
	 */
	public function test_playlist_id_of_a_trashed_post_renders_none_of_its_children(): void {
		$this->act_as_agent();
		$parent = $this->admin_post( array( 'post_status' => 'trash' ) );
		$this->audio_track( 'steadhand-trashed-parent.mp3', 'TRASHED-TITLE-PLAYLIST-ID', $parent );

		$html = $this->rendered( '[playlist id="' . $parent . '"]' );

		$this->assertStringNotContainsString( 'steadhand-trashed-parent.mp3', $html, 'The child track must not render.' );
		$this->assertStringNotContainsString( 'TRASHED-TITLE-PLAYLIST-ID', $html, 'The child title must not render.' );
	}

	public function test_gallery_ids_renders_unattached_images_and_images_of_a_published_allowed_post(): void {
		$this->act_as_agent();
		$unattached = $this->captioned_image( 'PUBLIC-CAPTION-UNATTACHED' );
		$parent     = $this->admin_post( array( 'post_status' => 'publish' ) );
		$attached   = $this->captioned_image( 'PUBLIC-CAPTION-PUBLISHED-PARENT', $parent );

		$html = $this->rendered( '[gallery ids="' . $unattached . ',' . $attached . '"]' );

		$this->assertTrue( $this->shows_image( $unattached, $html ), 'An unattached image must render.' );
		$this->assertTrue( $this->shows_image( $attached, $html ), 'An image of a published allowed post must render.' );
		$this->assertStringContainsString( 'PUBLIC-CAPTION-PUBLISHED-PARENT', $html, 'The caption must render with the image.' );
		$this->assertStringContainsString( get_attachment_link( $unattached ), $html, 'The attachment link must render with the image.' );
	}

	public function test_gallery_with_no_attributes_renders_the_images_of_the_post_being_rendered(): void {
		$this->act_as_agent();
		$post  = $this->admin_post( array( 'post_content' => '[gallery]' ) );
		$image = $this->captioned_image( 'PUBLIC-CAPTION-OWN-POST', $post );

		$html = $this->output( self::TOOL_NAME, array( 'id' => $post ) )['html'];

		$this->assertTrue( $this->shows_image( $image, $html ), 'The post\'s own image must render through a bare [gallery].' );
	}

	public function test_gallery_id_of_a_readable_post_renders_its_images(): void {
		$this->act_as_agent();
		$parent = $this->admin_post( array( 'post_status' => 'publish' ) );
		$image  = $this->captioned_image( 'PUBLIC-CAPTION-ID-PARENT', $parent );

		$html = $this->rendered( '[gallery id="' . $parent . '"]' );

		$this->assertTrue( $this->shows_image( $image, $html ), 'The image of a readable post must render through id.' );
	}

	public function test_playlist_ids_and_id_of_a_readable_post_render_their_tracks(): void {
		$this->act_as_agent();
		$loose  = $this->audio_track( 'steadhand-public-loose.mp3', 'PUBLIC-TITLE-LOOSE' );
		$parent = $this->admin_post( array( 'post_status' => 'publish' ) );
		$this->audio_track( 'steadhand-public-child.mp3', 'PUBLIC-TITLE-CHILD', $parent );

		$html = $this->rendered( '[playlist ids="' . $loose . '"][playlist id="' . $parent . '"]' );

		$this->assertStringContainsString( 'steadhand-public-loose.mp3', $html, 'An unattached track must render.' );
		$this->assertStringContainsString( 'steadhand-public-child.mp3', $html, 'A track of a readable post must render through id.' );
	}

	public function test_text_around_a_refused_shortcode_still_renders(): void {
		$this->act_as_agent();
		$secret = $this->captioned_image( 'SECRET-CAPTION-AROUND', $this->secret_post( 'private' ) );

		$html = $this->rendered( 'BEFORE-MARKER [gallery ids="' . $secret . '"] AFTER-MARKER' );

		$this->assertStringContainsString( 'BEFORE-MARKER', $html );
		$this->assertStringContainsString( 'AFTER-MARKER', $html );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-AROUND', $html );
	}

	public function test_the_shortcode_guard_is_removed_after_a_render_and_does_not_act_outside_it(): void {
		$this->act_as_agent();
		$secret = $this->captioned_image( 'SECRET-CAPTION-OUTSIDE', $this->secret_post( 'private' ) );
		$post   = $this->admin_post( array( 'post_content' => '[gallery ids="' . $secret . '"]' ) );

		$before = $this->shortcode_filter_count();
		$this->output( self::TOOL_NAME, array( 'id' => $post ) );
		$this->assertSame( $before, $this->shortcode_filter_count(), 'The shortcode guard must be removed after the render.' );

		// Outside get-rendered the guard is not attached, so core's own output is unchanged.
		$this->assertStringContainsString( 'SECRET-CAPTION-OUTSIDE', do_shortcode( '[gallery ids="' . $secret . '"]' ) );
	}

	/**
	 * An empty id is read as no parent, so core lists only the unattached attachments, which get-media reads. Without the
	 * guard this test passes (no attachment with a parent is listed), so it is kept as a regression test for the guard's
	 * refusal of the form.
	 */
	public function test_gallery_with_an_empty_id_lists_no_secret_attachment(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'SECRET-CAPTION-EMPTY-ID', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[gallery id=""]' );

		$this->assert_image_hidden( $image, $html, 'gallery empty id' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-EMPTY-ID', $html, 'gallery empty id: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	/**
	 * An id of 0 with exclude lists only the unattached attachments, which get-media reads. Without the guard this test
	 * passes, so it is kept as a regression test for the guard's refusal of the form.
	 */
	public function test_gallery_exclude_with_id_zero_lists_no_secret_attachment(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'SECRET-CAPTION-EXCLUDE-ZERO', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[gallery exclude="1" id="0"]' );

		$this->assert_image_hidden( $image, $html, 'gallery exclude with id 0' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-EXCLUDE-ZERO', $html, 'gallery exclude with id 0: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	/**
	 * A negative ID in ids is read as its absolute value, so core fetches the attachment it names.
	 */
	public function test_gallery_ids_with_a_negative_id_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'SECRET-CAPTION-NEGATIVE-IDS', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[gallery ids="-' . $image . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery ids with a negative id' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-NEGATIVE-IDS', $html, 'gallery ids with a negative id: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	/**
	 * WordPress lowercases shortcode attribute names, so IDS is ids.
	 */
	public function test_gallery_with_an_upper_case_IDS_attribute_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'SECRET-CAPTION-UPPER-IDS', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[gallery IDS="' . $image . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery IDS' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-UPPER-IDS', $html, 'gallery IDS: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	/**
	 * An ids value of 0 counts as empty, so core uses the include list that follows it.
	 */
	public function test_gallery_with_ids_zero_and_an_include_renders_nothing_of_the_include(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'SECRET-CAPTION-IDS-ZERO-INCLUDE', $this->secret_post( 'private' ) );

		$html = $this->rendered( '[gallery ids="0" include="' . $image . '"]' );

		$this->assert_image_hidden( $image, $html, 'gallery ids 0 with include' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-IDS-ZERO-INCLUDE', $html, 'gallery ids 0 with include: the caption must not render.' );
		$this->assert_get_media_refuses( $image );
	}

	/**
	 * The same gallery inside a shortcode block, which runs through do_shortcode() and so through the same guard.
	 */
	public function test_a_shortcode_block_with_gallery_ids_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$image = $this->captioned_image( 'SECRET-CAPTION-SHORTCODE-BLOCK', $this->secret_post( 'private' ) );

		$html = $this->rendered(
			"<!-- wp:paragraph -->\n<p>CONTROL-SHORTCODE-BLOCK</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->[gallery ids=\"$image\"]<!-- /wp:shortcode -->"
		);

		$this->assert_image_hidden( $image, $html, 'shortcode block gallery ids' );
		$this->assertStringNotContainsString( 'SECRET-CAPTION-SHORTCODE-BLOCK', $html, 'shortcode block: the caption must not render.' );
		$this->assertStringContainsString( 'CONTROL-SHORTCODE-BLOCK', $html );
		$this->assert_get_media_refuses( $image );
	}
}
