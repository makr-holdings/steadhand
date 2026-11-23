<?php
/**
 * The steadhand/get-rendered render guard for core blocks that print data the agent may not read (omit_block()).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Covers the pre_render_block guard for legacy widgets, post-meta bindings, breadcrumbs, author links and bios, and
 * video dimensions (SPEC §5, §14.1). Each negative test names data the agent may not read, with a marker string that
 * must not render. Each has a positive control in the same post, so an empty render cannot pass by accident.
 *
 * A test whose block is not registered in the running WordPress version is skipped, not failed.
 */
final class GetRenderedBlockGuardsTest extends MediaToolTestCase {

	private const TOOL_NAME = 'steadhand-get-rendered';

	/**
	 * Meta key registered for the post-meta binding tests, with show_in_rest so that core would return its value.
	 */
	private const META_KEY = 'steadhand_probe_meta';

	/**
	 * Removes the registered meta key, the widget options and the sidebar assignments this class sets.
	 */
	protected function tearDown(): void {
		unregister_meta_key( 'post', self::META_KEY );
		$this->set_plain_permalinks();
		delete_option( 'widget_text' );
		delete_option( 'widget_media_gallery' );
		wp_set_sidebars_widgets( array() );

		parent::tearDown();
	}

	/**
	 * Renders content in a new published post, as the agent, and returns the HTML.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $fields  Other post fields.
	 */
	private function rendered( string $content, array $fields = array() ): string {
		$post = $this->admin_post( array_merge( array( 'post_content' => $content ), $fields ) );

		return $this->output( self::TOOL_NAME, array( 'id' => $post ) )['html'];
	}

	/**
	 * Skips the calling test when the block is not registered in this WordPress version.
	 *
	 * @param string $name Block name, e.g. core/breadcrumbs.
	 */
	private function require_block( string $name ): void {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			$this->markTestSkipped( "$name is not registered in this WordPress version." );
		}
	}

	/**
	 * Stores one widget instance, places it in a registered probe sidebar, and registers the instance so that
	 * wp_render_widget() can find it. Core's widgets_init pass skips a widget base that is already registered, so the
	 * instance is registered directly. The sidebar is registered in full: the inactive-widgets pseudo sidebar has no
	 * wrapper markup, and a widget rendered into it raises a warning.
	 *
	 * @param string               $widget_class Widget class, e.g. WP_Widget_Text.
	 * @param string               $base      Widget base ID, e.g. text.
	 * @param int                  $number    Instance number.
	 * @param array<string, mixed> $instance  Instance settings.
	 */
	private function place_widget( string $widget_class, string $base, int $number, array $instance ): string {
		global $wp_widget_factory;

		register_sidebar(
			array(
				'id'            => 'steadhand-probe-sidebar',
				'name'          => 'Steadhand probe sidebar',
				'before_widget' => '<div class="%2$s">',
				'after_widget'  => '</div>',
				'before_title'  => '<h2>',
				'after_title'   => '</h2>',
			)
		);
		$widget_id = $base . '-' . $number;
		update_option(
			'widget_' . $base,
			array(
				'_multiwidget' => 1,
				$number        => $instance,
			)
		);
		// wp_set_sidebars_widgets() also clears core's cached copy of the assignments, which update_option() does not.
		wp_set_sidebars_widgets( array( 'steadhand-probe-sidebar' => array( $widget_id ) ) );
		$wp_widget_factory->widgets[ $widget_class ]->_register();

		return $widget_id;
	}

	/**
	 * Whether the HTML contains a gallery item for this attachment (its attachment link, as core prints it).
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $html Rendered HTML.
	 */
	private function shows_attachment( int $id, string $html ): bool {
		return 1 === preg_match( '/' . preg_quote( get_attachment_link( $id ), '/' ) . '(?!\d)/', $html );
	}

	/**
	 * An image under the given parent. Callers pass a private post of the non-allowed type, so get-media refuses the
	 * image and no render may show it.
	 *
	 * @param int $parent_id Parent post ID.
	 */
	private function secret_image_under( int $parent_id ): int {
		return $this->image( $parent_id, array( 'post_excerpt' => 'SECRET-CAPTION-BLOCK-GUARD' ) );
	}

	/**
	 * A post of the non-allowed type, private.
	 */
	private function secret_private_post(): int {
		return $this->admin_post(
			array(
				'post_type'   => self::SECRET_TYPE,
				'post_status' => 'private',
				'post_title'  => 'SECRET-PARENT-BLOCK-GUARD',
			)
		);
	}

	/**
	 * Pretty permalinks, so an author archive URL holds the nicename, as on a real site. With plain permalinks the URL
	 * holds the user ID instead, and a nicename check would pass for the wrong reason.
	 */
	private function use_pretty_permalinks(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Restores plain permalinks, the structure the test site starts with.
	 */
	private function set_plain_permalinks(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );
	}

	/**
	 * An author with a distinctive display name, nicename (which the archive URL holds) and biography. Also sets
	 * pretty permalinks, and checks that the author's archive URL really holds the nicename.
	 */
	private function probe_author(): int {
		$this->use_pretty_permalinks();
		$id = self::factory()->user->create(
			array(
				'role'          => 'author',
				'display_name'  => 'Probe Author Name',
				'user_nicename' => 'probe-nicename-7731',
				'description'   => 'BIO-MARKER-AUTHOR-7731',
			)
		);
		$this->assertStringContainsString( 'probe-nicename-7731', get_author_posts_url( $id ), 'The archive URL must hold the nicename for the link tests to mean anything.' );

		return $id;
	}


	/**
	 * A legacy widget with the id of a stored text widget prints that widget. Steadhand omits every legacy widget.
	 */
	public function test_a_legacy_widget_with_the_id_of_a_stored_text_widget_renders_nothing_of_it(): void {
		$this->act_as_agent();
		$widget = $this->place_widget(
			'WP_Widget_Text',
			'text',
			7,
			array(
				'title'  => '',
				'text'   => 'WIDGET-TEXT-MARKER-7731',
				'filter' => false,
			)
		);

		$html = $this->rendered(
			"<!-- wp:paragraph -->\n<p>CONTROL-BEFORE-WIDGET</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:legacy-widget {\"id\":\"$widget\"} /-->\n\n<!-- wp:paragraph -->\n<p>CONTROL-AFTER-WIDGET</p>\n<!-- /wp:paragraph -->"
		);

		$this->assertStringNotContainsString( 'WIDGET-TEXT-MARKER-7731', $html );
		$this->assertStringContainsString( 'CONTROL-BEFORE-WIDGET', $html );
		$this->assertStringContainsString( 'CONTROL-AFTER-WIDGET', $html );
	}

	/**
	 * A media gallery widget calls gallery_shortcode() itself, so the shortcode guard does not see it. The legacy widget
	 * block is omitted, so the file of an attachment the agent may not read does not render.
	 */
	public function test_a_legacy_widget_with_the_id_of_a_media_gallery_widget_renders_no_file_of_a_secret_attachment(): void {
		$this->act_as_agent();
		$image  = $this->secret_image_under( $this->secret_private_post() );
		$widget = $this->place_widget(
			'WP_Widget_Media_Gallery',
			'media_gallery',
			4,
			array(
				'title'          => '',
				// Core's gallery prints nothing for an array of IDs in this version, so the string form is sent.
				'ids'            => (string) $image,
				'columns'        => 3,
				'size'           => 'thumbnail',
				'link_type'      => 'file',
				'orderby_random' => false,
			)
		);

		$html = $this->rendered(
			"<!-- wp:paragraph -->\n<p>CONTROL-GALLERY-WIDGET</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:legacy-widget {\"id\":\"$widget\"} /-->"
		);

		$this->assertStringNotContainsString( wp_basename( (string) wp_get_attachment_url( $image ) ), $html, 'The file must not render.' );
		$this->assertFalse( $this->shows_attachment( $image, $html ), 'The gallery item must not render.' );
		$this->assertStringContainsString( 'CONTROL-GALLERY-WIDGET', $html );
	}


	/**
	 * A paragraph whose content is bound to post meta prints the meta value. The binding is omitted with its block.
	 */
	public function test_a_paragraph_bound_to_post_meta_renders_no_meta_value(): void {
		register_post_meta(
			'post',
			self::META_KEY,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
		$this->act_as_agent();
		$id = $this->admin_post(
			array(
				'post_content' => '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"' . self::META_KEY . '"}}}}} --><p>FALLBACK-TEXT-BINDING</p><!-- /wp:paragraph -->' . "\n\n" . '<!-- wp:paragraph -->' . "\n" . '<p>CONTROL-AFTER-BINDING</p>' . "\n" . '<!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $id, self::META_KEY, 'META-VALUE-MARKER-7731' );

		$html = $this->output( self::TOOL_NAME, array( 'id' => $id ) )['html'];

		$this->assertStringNotContainsString( 'META-VALUE-MARKER-7731', $html, 'The bound meta value must not render.' );
		$this->assertStringContainsString( 'CONTROL-AFTER-BINDING', $html );
	}

	/**
	 * A paragraph with no bindings renders its own text, so the guard does not touch ordinary blocks.
	 */
	public function test_a_paragraph_without_bindings_renders_its_text(): void {
		$this->act_as_agent();

		$html = $this->rendered( "<!-- wp:paragraph -->\n<p>PLAIN-PARAGRAPH-CONTROL</p>\n<!-- /wp:paragraph -->" );

		$this->assertStringContainsString( 'PLAIN-PARAGRAPH-CONTROL', $html );
	}


	/**
	 * A page under a private parent of the non-allowed type: breadcrumbs print the parent's title with no status check.
	 */
	public function test_breadcrumbs_under_a_private_parent_of_a_secret_type_show_no_parent_title(): void {
		$this->require_block( 'core/breadcrumbs' );
		$this->act_as_agent();
		$parent = $this->secret_private_post();

		$html = $this->rendered(
			"<!-- wp:paragraph -->\n<p>CONTROL-BREADCRUMBS</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:breadcrumbs /-->",
			array(
				'post_type'   => 'page',
				'post_parent' => $parent,
			)
		);

		$this->assertStringNotContainsString( 'SECRET-PARENT-BLOCK-GUARD', $html );
		$this->assertStringContainsString( 'CONTROL-BREADCRUMBS', $html );
	}

	/**
	 * A page under a trashed parent: breadcrumbs print the trashed title too.
	 */
	public function test_breadcrumbs_under_a_trashed_parent_show_no_parent_title(): void {
		$this->require_block( 'core/breadcrumbs' );
		$this->act_as_agent();
		$parent = $this->admin_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'trash',
				'post_title'  => 'TRASHED-PARENT-BLOCK-GUARD',
			)
		);

		$html = $this->rendered(
			'<!-- wp:breadcrumbs /-->',
			array(
				'post_type'   => 'page',
				'post_parent' => $parent,
			)
		);

		$this->assertStringNotContainsString( 'TRASHED-PARENT-BLOCK-GUARD', $html );
	}


	/**
	 * A post author block with showBio prints the author's biography.
	 */
	public function test_a_post_author_block_with_show_bio_prints_no_biography(): void {
		$this->require_block( 'core/post-author' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered(
			"<!-- wp:post-author {\"showBio\":true} /-->\n\n<!-- wp:paragraph -->\n<p>CONTROL-AUTHOR-BIO</p>\n<!-- /wp:paragraph -->",
			array( 'post_author' => $author )
		);

		$this->assertStringNotContainsString( 'BIO-MARKER-AUTHOR-7731', $html );
		$this->assertStringContainsString( 'CONTROL-AUTHOR-BIO', $html );
	}

	/**
	 * A post author block with isLink links to the author archive, which holds the user's nicename.
	 */
	public function test_a_post_author_block_with_is_link_prints_no_author_archive_link(): void {
		$this->require_block( 'core/post-author' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered(
			'<!-- wp:post-author {"isLink":true,"linkTarget":"_self"} /-->',
			array( 'post_author' => $author )
		);

		$this->assertStringNotContainsString( 'probe-nicename-7731', $html );
	}

	/**
	 * A post author name block with isLink links to the author archive.
	 */
	public function test_a_post_author_name_block_with_is_link_prints_no_author_archive_link(): void {
		$this->require_block( 'core/post-author-name' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered(
			'<!-- wp:post-author-name {"isLink":true,"linkTarget":"_self"} /-->',
			array( 'post_author' => $author )
		);

		$this->assertStringNotContainsString( 'probe-nicename-7731', $html );
	}

	/**
	 * A post author biography block prints the biography, which is user data.
	 */
	public function test_a_post_author_biography_block_prints_no_biography_and_no_archive_link(): void {
		$this->require_block( 'core/post-author-biography' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered(
			"<!-- wp:post-author-biography /-->\n\n<!-- wp:paragraph -->\n<p>CONTROL-BIOGRAPHY</p>\n<!-- /wp:paragraph -->",
			array( 'post_author' => $author )
		);

		$this->assertStringNotContainsString( 'BIO-MARKER-AUTHOR-7731', $html );
		$this->assertStringNotContainsString( 'probe-nicename-7731', $html );
		$this->assertStringContainsString( 'CONTROL-BIOGRAPHY', $html );
	}

	/**
	 * Control: a plain post author name, with no link, still prints the author's name, which is already part of the tool's
	 * output.
	 */
	public function test_a_plain_post_author_name_still_prints_the_name(): void {
		$this->require_block( 'core/post-author-name' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered(
			'<!-- wp:post-author-name /-->',
			array( 'post_author' => $author )
		);

		$this->assertStringContainsString( 'Probe Author Name', $html );
	}


	/**
	 * A video block with the id of a video under a private post of the non-allowed type gets its width and height from
	 * that attachment's metadata, so those numbers must not render.
	 */
	public function test_a_video_block_with_the_id_of_a_secret_attachment_prints_no_dimensions(): void {
		$this->act_as_agent();
		$video = $this->plain( 'video/mp4', $this->secret_private_post(), array( 'post_title' => 'SECRET-VIDEO' ) );
		wp_update_attachment_metadata(
			$video,
			array(
				'width'  => 7171,
				'height' => 3131,
				'file'   => 'steadhand-secret-video.mp4',
			)
		);

		$html = $this->rendered(
			'<!-- wp:video {"id":' . $video . '} --><figure class="wp-block-video"><video controls src="https://example.org/steadhand-secret-video.mp4"></video></figure><!-- /wp:video -->'
		);

		$this->assertStringNotContainsString( '7171', $html, 'The width must not render.' );
		$this->assertStringNotContainsString( '3131', $html, 'The height must not render.' );
	}

	/**
	 * Control: a video with the id of a readable attachment gets its dimensions, as core adds them.
	 */
	public function test_a_video_block_with_the_id_of_a_readable_attachment_gets_its_dimensions(): void {
		$this->act_as_agent();
		$video = $this->plain( 'video/mp4', 0, array( 'post_title' => 'Readable video' ) );
		wp_update_attachment_metadata(
			$video,
			array(
				'width'  => 6161,
				'height' => 4141,
				'file'   => 'steadhand-readable-video.mp4',
			)
		);

		$html = $this->rendered(
			'<!-- wp:video {"id":' . $video . '} --><figure class="wp-block-video"><video controls src="https://example.org/steadhand-readable-video.mp4"></video></figure><!-- /wp:video -->'
		);

		$this->assertStringContainsString( 'width="6161"', $html );
		$this->assertStringContainsString( 'height="4141"', $html );
	}

	/**
	 * Control: a video with no id renders as it is saved, and no id means no attachment to check.
	 */
	public function test_a_video_block_without_an_id_renders(): void {
		$this->act_as_agent();

		$html = $this->rendered(
			'<!-- wp:video --><figure class="wp-block-video"><video controls src="https://example.org/no-id-video.mp4"></video></figure><!-- /wp:video -->'
		);

		$this->assertStringContainsString( 'https://example.org/no-id-video.mp4', $html );
	}
}
