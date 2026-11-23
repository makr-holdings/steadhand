<?php
/**
 * The steadhand/get-rendered render guard for core blocks that print data the agent may not read (omit_block()).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Covers the render guards for legacy widgets, post-meta bindings, breadcrumbs, author links and bios, video dimensions,
 * and navigation links bound to posts or terms (SPEC §5, §14.1). Each negative test names data the agent may not read,
 * with a marker string that must not render. Each has a positive control in the same post, so an empty render cannot
 * pass by accident.
 *
 * The wrapper tests put guarded blocks inside core/navigation, core/navigation-submenu and core/widget-group. Those
 * blocks render their children by calling render() directly, so pre_render_block never sees the children; only the
 * render_block layer can omit them. All three wrappers render every child type used here (their render code does not
 * filter children by type), so each wrapper test uses the same children.
 *
 * The priority test is weaker than its name suggests. The guards are attached at PHP_INT_MAX during the render, after
 * any callback a test registers before it, so a test callback at the same priority runs first and the guard still
 * wins. The render_block layer is proven by the wrapper tests, which never reach pre_render_block at all.
 *
 * A test whose block, binding source or taxonomy is not available in the running WordPress version is skipped, not failed.
 */
final class GetRenderedBlockGuardsTest extends MediaToolTestCase {

	private const TOOL_NAME = 'steadhand-get-rendered';

	/**
	 * Meta key registered for the post-meta binding tests, with show_in_rest so that core would return its value.
	 */
	private const META_KEY = 'steadhand_probe_meta';

	/**
	 * Taxonomy that is not publicly queryable, for the term-data binding test.
	 */
	private const PRIVATE_TAXONOMY = 'steadhand_secret_tax';

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
	 * Renders content in a new published post that has the probe meta value, as the agent, and returns the HTML. The
	 * meta is stored after the post exists, so the bindings read it for this post.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $fields  Other post fields.
	 */
	private function rendered_with_meta( string $content, array $fields = array() ): string {
		register_post_meta(
			'post',
			self::META_KEY,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
		$post = $this->admin_post( array_merge( array( 'post_content' => $content ), $fields ) );
		update_post_meta( $post, self::META_KEY, 'META-VALUE-MARKER-7731' );

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
	 * Skips the calling test when the block bindings source is not registered in this WordPress version.
	 *
	 * @param string $name Source name, e.g. core/post-data.
	 */
	private function require_binding_source( string $name ): void {
		if ( null === get_block_bindings_source( $name ) ) {
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
	 * Places a stored text widget and returns the legacy-widget block markup that points at it.
	 *
	 * @param string $marker Text that the widget prints, which the tests must not find in the HTML.
	 */
	private function legacy_text_widget_block( string $marker ): string {
		$widget = $this->place_widget(
			'WP_Widget_Text',
			'text',
			7,
			array(
				'title'  => '',
				'text'   => $marker,
				'filter' => false,
			)
		);

		return '<!-- wp:legacy-widget {"id":"' . $widget . '"} /-->';
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
	 * How many callbacks are attached at PHP_INT_MAX to a hook. Used to check that the render guards are removed.
	 *
	 * @param string $hook Hook name.
	 */
	private function max_priority_callbacks( string $hook ): int {
		global $wp_filter;

		return isset( $wp_filter[ $hook ] ) ? count( $wp_filter[ $hook ]->callbacks[ PHP_INT_MAX ] ?? array() ) : 0;
	}

	/**
	 * Blocks that core's wrappers render directly, each printing data the agent may not read: a logout link, the author
	 * biography, a linked author name, and a value bound to post meta.
	 */
	private function guarded_children(): string {
		return implode(
			"\n\n",
			array(
				'<!-- wp:loginout /-->',
				'<!-- wp:post-author-biography /-->',
				'<!-- wp:post-author-name {"isLink":true,"linkTarget":"_self"} /-->',
				'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"' . self::META_KEY . '"}}}}} --><p>FALLBACK-BOUND-TEXT</p><!-- /wp:paragraph -->',
			)
		);
	}

	/**
	 * A paragraph with the given text, as block markup.
	 *
	 * @param string $text Paragraph text.
	 */
	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Asserts that none of the guarded children's data is in the HTML.
	 *
	 * @param string $html Rendered HTML.
	 */
	private function assert_guarded_children_absent( string $html ): void {
		$this->assertStringNotContainsString( 'action=logout', $html, 'The logout link must not render.' );
		$this->assertStringNotContainsString( 'BIO-MARKER-AUTHOR-7731', $html, 'The biography must not render.' );
		$this->assertStringNotContainsString( 'probe-nicename-7731', $html, 'The author archive link must not render.' );
		$this->assertStringNotContainsString( 'META-VALUE-MARKER-7731', $html, 'The bound meta value must not render.' );
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
	 * A legacy widget inside a widget group is a child that core's widget group renders directly.
	 */
	public function test_a_legacy_widget_inside_a_widget_group_renders_nothing_of_it(): void {
		$this->require_block( 'core/widget-group' );
		$this->act_as_agent();

		$html = $this->rendered(
			'<!-- wp:widget-group -->' . "\n" . $this->legacy_text_widget_block( 'WIDGET-GROUP-MARKER-7731' ) . "\n" . '<!-- /wp:widget-group -->' . "\n\n" . $this->paragraph( 'CONTROL-WIDGET-GROUP' )
		);

		$this->assertStringNotContainsString( 'WIDGET-GROUP-MARKER-7731', $html );
		$this->assertStringContainsString( 'CONTROL-WIDGET-GROUP', $html );
	}

	/**
	 * A legacy widget inside a navigation block is a child that core's navigation renders directly.
	 */
	public function test_a_legacy_widget_inside_a_navigation_block_renders_nothing_of_it(): void {
		$this->require_block( 'core/navigation' );
		$this->act_as_agent();

		$html = $this->rendered(
			'<!-- wp:navigation -->' . "\n" . $this->legacy_text_widget_block( 'NAVIGATION-WIDGET-MARKER-7731' ) . "\n" . $this->paragraph( 'CONTROL-IN-NAVIGATION' ) . "\n" . '<!-- /wp:navigation -->'
		);

		$this->assertStringNotContainsString( 'NAVIGATION-WIDGET-MARKER-7731', $html );
		$this->assertStringContainsString( 'CONTROL-IN-NAVIGATION', $html );
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
			"<!-- wp:post-author {\"isLink\":true,\"linkTarget\":\"_self\"} /-->\n\n<!-- wp:paragraph -->\n<p>CONTROL-AUTHOR-LINK</p>\n<!-- /wp:paragraph -->",
			array( 'post_author' => $author )
		);

		$this->assertStringNotContainsString( 'probe-nicename-7731', $html );
		$this->assertStringContainsString( 'CONTROL-AUTHOR-LINK', $html );
	}

	/**
	 * A post author name block with isLink links to the author archive.
	 */
	public function test_a_post_author_name_block_with_is_link_prints_no_author_archive_link(): void {
		$this->require_block( 'core/post-author-name' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered(
			"<!-- wp:post-author-name {\"isLink\":true,\"linkTarget\":\"_self\"} /-->\n\n<!-- wp:paragraph -->\n<p>CONTROL-NAME-LINK</p>\n<!-- /wp:paragraph -->",
			array( 'post_author' => $author )
		);

		$this->assertStringNotContainsString( 'probe-nicename-7731', $html );
		$this->assertStringContainsString( 'CONTROL-NAME-LINK', $html );
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
	 * Core's navigation renders its children directly, so the guarded children must be omitted there too.
	 */
	public function test_guarded_blocks_inside_a_navigation_block_render_nothing_of_them(): void {
		$this->require_block( 'core/navigation' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered_with_meta(
			'<!-- wp:navigation -->' . "\n" . $this->guarded_children() . "\n" . $this->paragraph( 'CONTROL-IN-NAVIGATION' ) . "\n" . '<!-- /wp:navigation -->' . "\n\n" . $this->paragraph( 'CONTROL-AFTER-NAVIGATION' ),
			array( 'post_author' => $author )
		);

		$this->assert_guarded_children_absent( $html );
		$this->assertStringContainsString( 'CONTROL-IN-NAVIGATION', $html );
		$this->assertStringContainsString( 'CONTROL-AFTER-NAVIGATION', $html );
	}

	/**
	 * Core's navigation submenu renders its children directly. It needs a label, or it renders nothing at all.
	 */
	public function test_guarded_blocks_inside_a_navigation_submenu_render_nothing_of_them(): void {
		$this->require_block( 'core/navigation-submenu' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered_with_meta(
			'<!-- wp:navigation -->' . "\n" . '<!-- wp:navigation-submenu {"label":"SUBMENU-LABEL-7731","kind":"custom","url":"https://example.org/submenu-7731"} -->' . "\n" . $this->guarded_children() . "\n" . $this->paragraph( 'CONTROL-IN-SUBMENU' ) . "\n" . '<!-- /wp:navigation-submenu -->' . "\n" . '<!-- /wp:navigation -->',
			array( 'post_author' => $author )
		);

		$this->assert_guarded_children_absent( $html );
		$this->assertStringContainsString( 'CONTROL-IN-SUBMENU', $html, 'The submenu must render its own children, or the test proves nothing.' );
	}

	/**
	 * Core's widget group renders its children directly.
	 */
	public function test_guarded_blocks_inside_a_widget_group_render_nothing_of_them(): void {
		$this->require_block( 'core/widget-group' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$html = $this->rendered_with_meta(
			'<!-- wp:widget-group -->' . "\n" . $this->guarded_children() . "\n" . $this->paragraph( 'CONTROL-IN-WIDGET-GROUP' ) . "\n" . '<!-- /wp:widget-group -->' . "\n\n" . $this->paragraph( 'CONTROL-AFTER-WIDGET-GROUP' ),
			array( 'post_author' => $author )
		);

		$this->assert_guarded_children_absent( $html );
		$this->assertStringContainsString( 'CONTROL-IN-WIDGET-GROUP', $html );
		$this->assertStringContainsString( 'CONTROL-AFTER-WIDGET-GROUP', $html );
	}

	/**
	 * A callback at a later priority that returns null, for pre_render_block, cannot put an omitted block back. The
	 * guards are attached at PHP_INT_MAX, so a test callback at PHP_INT_MAX registered before the render runs first; see
	 * the class docblock for what this does and does not prove.
	 */
	public function test_pre_render_block_callbacks_at_later_priorities_cannot_put_back_an_omitted_block(): void {
		$this->require_block( 'core/post-author-biography' );
		$this->act_as_agent();
		$author = $this->probe_author();

		$undo_at_11  = static fn(): mixed => null;
		$undo_at_max = static fn(): mixed => null;
		add_filter( 'pre_render_block', $undo_at_11, 11, 3 );
		add_filter( 'pre_render_block', $undo_at_max, PHP_INT_MAX, 3 );

		try {
			$html = $this->rendered(
				"<!-- wp:post-author-biography /-->\n\n<!-- wp:paragraph -->\n<p>CONTROL-PRIORITY</p>\n<!-- /wp:paragraph -->",
				array( 'post_author' => $author )
			);
		} finally {
			remove_filter( 'pre_render_block', $undo_at_11, 11 );
			remove_filter( 'pre_render_block', $undo_at_max, PHP_INT_MAX );
		}

		$this->assertStringNotContainsString( 'BIO-MARKER-AUTHOR-7731', $html );
		$this->assertStringContainsString( 'CONTROL-PRIORITY', $html );
	}


	/**
	 * A navigation link bound to core/post-data with the id of a published post of the non-allowed type would show that
	 * post's permalink, because the agent's role may read the post. Omitting the link keeps the permalink out.
	 *
	 * The post is published, not private: core's navigation link hides a link to an unpublished post before the binding
	 * is read, so a private post would pass with or without the rule.
	 */
	public function test_a_navigation_link_bound_to_post_data_renders_no_link_to_a_post_the_agent_may_not_read(): void {
		$this->require_block( 'core/navigation-link' );
		$this->require_binding_source( 'core/post-data' );
		$this->act_as_agent();
		$secret    = $this->admin_post(
			array(
				'post_type'   => self::SECRET_TYPE,
				'post_status' => 'publish',
				'post_name'   => 'steadhand-secret-slug-7731',
				'post_title'  => 'SECRET-LINK-TARGET',
			)
		);
		$permalink = get_permalink( $secret );

		$html = $this->rendered(
			'<!-- wp:navigation -->' . "\n" . '<!-- wp:navigation-link {"label":"LINK-LABEL-7731","kind":"post-type","type":"' . self::SECRET_TYPE . '","id":' . $secret . ',"metadata":{"bindings":{"url":{"source":"core/post-data","args":{"field":"link"}}}}} /-->' . "\n" . $this->paragraph( 'CONTROL-IN-LINK-NAVIGATION' ) . "\n" . '<!-- /wp:navigation -->'
		);

		$this->assertIsString( $permalink );
		$this->assertStringNotContainsString( $permalink, $html, 'The permalink of the secret post must not render.' );
		$this->assertStringNotContainsString( 'steadhand-secret-slug-7731', $html, 'The slug of the secret post must not render.' );
		$this->assertStringContainsString( 'CONTROL-IN-LINK-NAVIGATION', $html );
	}

	/**
	 * A navigation link bound to core/term-data with the id of a term in a taxonomy that is not publicly queryable would
	 * show that term's link, because the agent has the read capability. Omitting the link keeps the term out.
	 */
	public function test_a_navigation_link_bound_to_term_data_renders_no_link_to_a_term_of_a_private_taxonomy(): void {
		$this->require_block( 'core/navigation-link' );
		$this->require_binding_source( 'core/term-data' );
		$this->act_as_agent();
		register_taxonomy(
			self::PRIVATE_TAXONOMY,
			'post',
			array(
				'public'             => false,
				'publicly_queryable' => false,
			)
		);

		try {
			$term = self::factory()->term->create(
				array(
					'taxonomy' => self::PRIVATE_TAXONOMY,
					'name'     => 'SECRET-TERM-7731',
					'slug'     => 'steadhand-secret-term-7731',
				)
			);
			$link = get_term_link( $term, self::PRIVATE_TAXONOMY );
			$this->assertIsString( $link );

			$html = $this->rendered(
				'<!-- wp:navigation -->' . "\n" . '<!-- wp:navigation-link {"label":"TERM-LABEL-7731","kind":"taxonomy","type":"' . self::PRIVATE_TAXONOMY . '","id":' . $term . ',"metadata":{"bindings":{"url":{"source":"core/term-data","args":{"field":"link"}}}}} /-->' . "\n" . $this->paragraph( 'CONTROL-IN-TERM-NAVIGATION' ) . "\n" . '<!-- /wp:navigation -->'
			);
		} finally {
			unregister_taxonomy( self::PRIVATE_TAXONOMY );
		}

		$this->assertStringNotContainsString( $link, $html, 'The link of the term must not render.' );
		$this->assertStringNotContainsString( 'steadhand-secret-term-7731', $html, 'The slug of the term must not render.' );
		$this->assertStringContainsString( 'CONTROL-IN-TERM-NAVIGATION', $html );
	}

	/**
	 * Control: core/post-date reads core/post-data for the current post, and is not omitted. The source rule for
	 * navigation blocks must not reach it.
	 */
	public function test_an_ordinary_block_using_post_data_for_the_current_post_still_renders(): void {
		$this->require_block( 'core/post-date' );
		$this->act_as_agent();

		$html = $this->rendered(
			"<!-- wp:post-date /-->\n\n<!-- wp:paragraph -->\n<p>CONTROL-POST-DATE</p>\n<!-- /wp:paragraph -->"
		);

		$this->assertStringContainsString( 'wp-block-post-date', $html );
		$this->assertStringContainsString( '<time', $html );
		$this->assertStringContainsString( 'CONTROL-POST-DATE', $html );
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
			'<!-- wp:video {"id":' . $video . '} --><figure class="wp-block-video"><video controls src="https://example.org/steadhand-secret-video.mp4"></video></figure><!-- /wp:video -->' . "\n\n" . '<!-- wp:paragraph -->' . "\n" . '<p>CONTROL-SECRET-VIDEO</p>' . "\n" . '<!-- /wp:paragraph -->'
		);

		$this->assertStringNotContainsString( '7171', $html, 'The width must not render.' );
		$this->assertStringNotContainsString( '3131', $html, 'The height must not render.' );
		$this->assertStringContainsString( 'CONTROL-SECRET-VIDEO', $html );
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


	/**
	 * Both render guards, the one on pre_render_block and the one on render_block, are attached only for the render: the
	 * number of callbacks at PHP_INT_MAX on each hook is the same after a render as before it.
	 */
	public function test_both_render_guards_are_removed_after_a_render(): void {
		$this->act_as_agent();
		$before = array(
			$this->max_priority_callbacks( 'pre_render_block' ),
			$this->max_priority_callbacks( 'render_block' ),
		);

		$this->rendered( $this->paragraph( 'CONTROL-GUARDS-REMOVED' ) );

		$this->assertSame(
			$before,
			array(
				$this->max_priority_callbacks( 'pre_render_block' ),
				$this->max_priority_callbacks( 'render_block' ),
			),
			'The guards must be removed after the render.'
		);
	}
}
