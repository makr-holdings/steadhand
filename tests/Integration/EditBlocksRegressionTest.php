<?php
/**
 * Regression tests for steadhand/edit-blocks: ordinary posts stay editable with every untouched byte kept, ordinary sent blocks are
 * accepted (and flagged as the save filters change them), the negative cases are refused with the stored row unchanged, sent block
 * attributes are filtered, and a call with several operations, a failing filter, or an unusual PCRE state behaves.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\BlockEditor;
use Steadhand\Pipeline\PostWriter;

/**
 * Every refusal asserts that the stored row is byte-identical afterwards.
 */
final class EditBlocksRegressionTest extends PostWriteTestCase {

	private const ABILITY   = 'steadhand/edit-blocks';
	private const TOOL_NAME = 'steadhand-edit-blocks';

	/**
	 * A paragraph block as the editor writes it.
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Stores raw content on a fresh draft, and makes the agent the current user.
	 *
	 * @param string $content Raw content.
	 * @return int The post ID.
	 */
	private function raw_post( string $content ): int {
		global $wpdb;

		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'placeholder',
			)
		);
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stores the fixture as another process would leave it.
		clean_post_cache( $post );
		$this->act_as_agent();

		return $post;
	}

	/**
	 * Calls edit-blocks with the given operations on a post.
	 *
	 * @param int                              $post       Post ID.
	 * @param array<int, array<string, mixed>> $operations Operations.
	 * @return mixed
	 */
	private function call( int $post, array $operations ): mixed {
		return $this->execute_ability(
			self::ABILITY,
			array(
				'id'         => $post,
				'operations' => $operations,
			)
		);
	}

	/**
	 * The names of every block in the content, nested ones included, in document order.
	 *
	 * @param string $content Content.
	 * @return array<int, string>
	 */
	private static function names( string $content ): array {
		$out  = array();
		$walk = static function ( array $blocks ) use ( &$walk, &$out ): void {
			foreach ( $blocks as $block ) {
				if ( null !== $block['blockName'] ) {
					$out[] = $block['blockName'];
				}
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( parse_blocks( $content ) );

		return $out;
	}

	/**
	 * The path of the last top-level block of the stored content, which the tests edit.
	 *
	 * @param string $content Stored content.
	 * @return string
	 */
	private static function last_path( string $content ): string {
		$spans = BlockEditor::spans( $content );
		return (string) ( count( (array) $spans ) - 1 );
	}


	/**
	 * Admin content that must survive an edit of the paragraph after it.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function ordinary_stored_content(): array {
		$image   = '<!-- wp:image {"id":12,"sizeSlug":"large","linkDestination":"none"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="https://example.com/a.jpg" alt="Salt &amp; pepper" class="wp-image-12"/></figure>' . "\n" . '<!-- /wp:image -->';
		$gallery = '<!-- wp:gallery {"ids":[1,2],"linkTo":"none"} -->' . "\n" . '<figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":1} -->' . "\n" . '<figure class="wp-block-image"><img src="https://example.com/1.jpg" alt="" class="wp-image-1"/></figure>' . "\n" . '<!-- /wp:image --></figure>' . "\n" . '<!-- /wp:gallery -->';
		$cover   = '<!-- wp:cover {"url":"https://example.com/c.jpg","id":3,"dimRatio":50} -->' . "\n" . '<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-3" alt="" src="https://example.com/c.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container">' . self::paragraph( 'Hi' ) . '</div></div>' . "\n" . '<!-- /wp:cover -->';
		$sep     = '<!-- wp:separator -->' . "\n" . '<hr class="wp-block-separator has-alpha-channel-opacity"/>' . "\n" . '<!-- /wp:separator -->';
		$embed   = '<!-- wp:embed {"url":"https://example.com/watch?v=1&t=2","type":"video","providerNameSlug":"example"} -->' . "\n" . '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . "\n" . 'https://example.com/watch?v=1&t=2' . "\n" . '</div></figure>' . "\n" . '<!-- /wp:embed -->';
		$kadence = '<!-- wp:kadence/rowlayout {"uniqueID":"abc","columnLayout":"equal","bgColor":"#ffffff","margin":[{"unit":"px","top":"20","bottom":"20"}],"label":"Hero & intro","link":"https://example.com/?a=1&b=2","hideAt":false} -->' . "\n" . '<div class="wp-block-kadence-rowlayout">' . self::paragraph( 'Inside' ) . '</div>' . "\n" . '<!-- /wp:kadence/rowlayout -->';
		$mark    = '<!-- wp:paragraph -->' . "\n" . '<p><mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color">Marked</mark></p>' . "\n" . '<!-- /wp:paragraph -->';
		$script  = '<!-- wp:html -->' . "\n" . '<script>var x = 1 < 2;</script>' . "\n" . '<!-- /wp:html -->';
		$iframe  = '<!-- wp:html -->' . "\n" . '<iframe src="https://example.com/frame"></iframe>' . "\n" . '<!-- /wp:html -->';
		$css     = '<!-- wp:paragraph {"style":{"css":"color:red"}} -->' . "\n" . '<p>Kept</p>' . "\n" . '<!-- /wp:paragraph -->';
		$note    = '<!-- wp:group -->' . "\n" . '<div class="wp-block-group"><!-- note -->' . self::paragraph( 'In group' ) . '</div>' . "\n" . '<!-- /wp:group -->';
		$more    = '<!-- wp:more -->' . "\n" . '<!--more-->' . "\n" . '<!-- /wp:more -->';
		$bare    = "<p>Intro</p>\n<!--more-->\n<p>After</p>";
		$escaped = '<!-- wp:paragraph {"label":"<!-- x"} -->' . "\n" . '<p>Escaped</p>' . "\n" . '<!-- /wp:paragraph -->';
		$multi   = "Héllo wörld 🙂\n\n" . $image . "\n\n" . self::paragraph( 'Ünïcode 🙂' );

		return array(
			'image with /> form'                       => array( $image ),
			'gallery with /> form'                     => array( $gallery ),
			'cover with /> form'                       => array( $cover ),
			'separator with /> form'                   => array( $sep ),
			'embed with & in the url attribute'        => array( $embed ),
			'large json attributes with &'             => array( $kadence ),
			'mark with a style'                        => array( $mark ),
			'admin custom html with a closed script'   => array( $script ),
			'admin custom html with an iframe'         => array( $iframe ),
			'block with custom css'                    => array( $css ),
			'ordinary comment inside a group'          => array( $note ),
			'stored wp:more block'                     => array( $more ),
			'stored bare more comment in classic'      => array( $bare ),
			'json attribute with an escaped <!--'      => array( $escaped ),
			'multibyte text before, between and after' => array( $multi ),
		);
	}

	/**
	 * The edit that the ordinary-content table runs.
	 *
	 * @dataProvider ordinary_stored_content
	 *
	 * @param string $admin Content the administrator stored.
	 */
	public function test_ordinary_stored_content_stays_editable_with_untouched_bytes_identical( string $admin ): void {
		$stored = $admin . "\n\n" . self::paragraph( 'Target' );
		$post   = $this->raw_post( $stored );
		$before = $this->row( $post );

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => self::last_path( $stored ),
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'Changed' ),
					),
				),
			)
		);

		$this->assertTrue( $out['changed'] );
		$this->assertFalse( $out['content_modified_on_save'] );
		$this->assertSame( $admin . "\n\n" . self::paragraph( 'Changed' ), get_post( $post )->post_content );
		$this->assertSame( $before['post_title'], $this->row( $post )['post_title'] );
	}



	/**
	 * Ordinary blocks, the names they parse to, and whether the save filter changes them.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>, 2: bool}>
	 */
	public static function ordinary_sent_blocks(): array {
		$list    = '<!-- wp:list -->' . "\n" . '<ul class="wp-block-list"><!-- wp:list-item -->' . "\n" . '<li>a</li>' . "\n" . '<!-- /wp:list-item --></ul>' . "\n" . '<!-- /wp:list -->';
		$group   = '<!-- wp:group -->' . "\n" . '<div class="wp-block-group">' . self::paragraph( 'a' ) . '</div>' . "\n" . '<!-- /wp:group -->';
		$columns = '<!-- wp:columns -->' . "\n" . '<div class="wp-block-columns"><!-- wp:column -->' . "\n" . '<div class="wp-block-column">' . self::paragraph( 'a' ) . '</div>' . "\n" . '<!-- /wp:column --><!-- wp:column -->' . "\n" . '<div class="wp-block-column">' . self::paragraph( 'b' ) . '</div>' . "\n" . '<!-- /wp:column --></div>' . "\n" . '<!-- /wp:columns -->';
		$buttons = '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons"><!-- wp:button -->' . "\n" . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Go</a></div>' . "\n" . '<!-- /wp:button --></div>' . "\n" . '<!-- /wp:buttons -->';

		return array(
			'paragraph'                      => array( self::paragraph( 'x' ), array( 'core/paragraph' ), false ),
			'heading'                        => array( '<!-- wp:heading {"level":2} -->' . "\n" . '<h2 class="wp-block-heading">Title</h2>' . "\n" . '<!-- /wp:heading -->', array( 'core/heading' ), false ),
			'list with list items'           => array( $list, array( 'core/list', 'core/list-item' ), false ),
			'image in the space form'        => array( '<!-- wp:image -->' . "\n" . '<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="" /></figure>' . "\n" . '<!-- /wp:image -->', array( 'core/image' ), false ),
			'image in the slash form'        => array( '<!-- wp:image -->' . "\n" . '<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure>' . "\n" . '<!-- /wp:image -->', array( 'core/image' ), true ),
			'group with inner blocks'        => array( $group, array( 'core/group', 'core/paragraph' ), false ),
			'columns with two columns'       => array( $columns, array( 'core/columns', 'core/column', 'core/paragraph', 'core/column', 'core/paragraph' ), false ),
			'buttons and a button'           => array( $buttons, array( 'core/buttons', 'core/button' ), false ),
			'table'                          => array( '<!-- wp:table -->' . "\n" . '<figure class="wp-block-table"><table><tbody><tr><td>a</td></tr></tbody></table></figure>' . "\n" . '<!-- /wp:table -->', array( 'core/table' ), false ),
			'quote'                          => array( '<!-- wp:quote -->' . "\n" . '<blockquote class="wp-block-quote"><p>Q</p></blockquote>' . "\n" . '<!-- /wp:quote -->', array( 'core/quote' ), false ),
			'code with an escaped less-than' => array( '<!-- wp:code -->' . "\n" . '<pre class="wp-block-code"><code>x &lt; y</code></pre>' . "\n" . '<!-- /wp:code -->', array( 'core/code' ), false ),
			'embed'                          => array( '<!-- wp:embed {"url":"https://example.com/x","type":"rich","providerNameSlug":"example"} -->' . "\n" . '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . "\n" . 'https://example.com/x' . "\n" . '</div></figure>' . "\n" . '<!-- /wp:embed -->', array( 'core/embed' ), false ),
			'separator in the slash form'    => array( '<!-- wp:separator -->' . "\n" . '<hr class="wp-block-separator"/>' . "\n" . '<!-- /wp:separator -->', array( 'core/separator' ), true ),
			'more block'                     => array( '<!-- wp:more -->' . "\n" . '<!--more-->' . "\n" . '<!-- /wp:more -->', array( 'core/more' ), false ),
			'nextpage block'                 => array( '<!-- wp:nextpage -->' . "\n" . '<!--nextpage-->' . "\n" . '<!-- /wp:nextpage -->', array( 'core/nextpage' ), false ),
		);
	}

	/**
	 * An ordinary block is accepted, parses to the expected names, and is flagged exactly when the save filter changes it.
	 *
	 * @dataProvider ordinary_sent_blocks
	 *
	 * @param string             $markup  The block as the agent sends it.
	 * @param array<int, string> $names  The block names the stored content parses to.
	 * @param bool               $flagged Whether the save filter changes it.
	 */
	public function test_an_ordinary_sent_block_is_accepted_with_the_expected_names_and_flag( string $markup, array $names, bool $flagged ): void {
		$post = $this->raw_post( self::paragraph( 'One' ) );

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '0',
						'name'   => 'core/paragraph',
						'markup' => $markup,
					),
				),
			)
		);

		$this->assertTrue( $out['changed'] );
		$this->assertSame( $names, self::names( (string) get_post( $post )->post_content ) );
		$this->assertSame( $flagged, $out['content_modified_on_save'] );
	}


	/**
	 * Break comments that are bare in sent markup.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function bare_break_comments(): array {
		return array(
			'bare more'     => array( self::paragraph( 'x' ) . "\n<!--more-->" ),
			'bare nextpage' => array( self::paragraph( 'x' ) . "\n<!--nextpage-->" ),
			'bare noteaser' => array( self::paragraph( 'x' ) . "\n<!--noteaser-->" ),
		);
	}

	/**
	 * A bare break comment is refused, and the row is unchanged.
	 *
	 * @dataProvider bare_break_comments
	 *
	 * @param string $markup Sent markup with a bare break comment.
	 */
	public function test_a_bare_break_comment_in_sent_markup_is_refused_and_the_row_is_unchanged( string $markup ): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'     => 'replace',
					'path'   => '1',
					'name'   => 'core/paragraph',
					'markup' => $markup,
				),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	/**
	 * Stored content that leaves HTML open before a removed block, joined to the content after it, is refused.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function open_html_before_a_removed_block(): array {
		return array(
			'open textarea'  => array( '<textarea>open' ),
			'open attribute' => array( '<p title="open' ),
			'open script'    => array( '<script>var x = 1;' ),
		);
	}

	/**
	 * A remove-only call that would join open HTML to the content after it is refused by the join check.
	 *
	 * @dataProvider open_html_before_a_removed_block
	 *
	 * @param string $open Stored HTML left open, as the first root text.
	 */
	public function test_a_remove_that_joins_open_stored_html_to_the_following_content_is_refused_and_the_row_is_unchanged( string $open ): void {
		$post   = $this->raw_post( $open . "\n\n" . self::paragraph( 'Middle' ) . "\n\n" . self::paragraph( 'After' ) );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'   => 'remove',
					'path' => '1',
					'name' => 'core/paragraph',
				),
			)
		);

		$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $result ) );
		$this->assertStringContainsString( 'removes the content between two of its parts', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $post ) );
	}

	/**
	 * Halves of a split delimiter that update-post stores, then an edit that removes the block between them.
	 *
	 * An edit either is refused, or it must not create a block that was not there. Either way the row keeps the names it had.
	 */
	public function test_removing_the_block_between_two_split_delimiter_halves_creates_no_new_block(): void {
		$first   = '<!-- wp:paragraph -->' . "\n" . '<p>a <!-- wp:template-part {"q":"--></p>' . "\n" . '<!-- /wp:paragraph -->';
		$middle  = self::paragraph( 'mid' );
		$last    = '<!-- wp:paragraph -->' . "\n" . '<p>b <!-- ","slug":"header","tagName":"script"} /--></p>' . "\n" . '<!-- /wp:paragraph -->';
		$content = $first . "\n\n" . $middle . "\n\n" . $last;

		// Branch 1: update-post stores the halves itself. If it refuses them, there is nothing stored to edit.
		$post    = $this->raw_post( self::paragraph( 'seed' ) );
		$written = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => $content,
			)
		);
		if ( is_array( $written ) ) {
			$this->assert_removing_the_middle_adds_no_block( $post );
		} else {
			$this->assertSame( self::paragraph( 'seed' ), (string) get_post( $post )->post_content, 'update-post refused the halves, so the row is unchanged.' );
		}

		// Branch 2: the same content stored as the administrator left it, without update-post's save.
		$raw = $this->raw_post( $content );
		$this->assert_removing_the_middle_adds_no_block( $raw );
	}

	/**
	 * Removes the middle block of a post that holds the split-delimiter content, and checks that no block name appears that the
	 * stored content did not have. A refused call must leave the row as it was.
	 *
	 * @param int $post Post ID.
	 */
	private function assert_removing_the_middle_adds_no_block( int $post ): void {
		$stored = (string) get_post( $post )->post_content;
		$names  = self::names( $stored );
		$index  = null;
		foreach ( (array) BlockEditor::spans( $stored ) as $i => $node ) {
			if ( str_contains( substr( $stored, $node['start'], $node['end'] - $node['start'] ), '>mid<' ) ) {
				$index = (string) $i;
			}
		}
		if ( null === $index ) {
			$this->assertSame( $stored, (string) get_post( $post )->post_content );
			return;
		}

		$before = $this->row( $post );
		$result = $this->call(
			$post,
			array(
				array(
					'op'   => 'remove',
					'path' => $index,
					'name' => 'core/paragraph',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->assertSame( $before, $this->row( $post ), 'A refused call leaves the row unchanged.' );
			return;
		}

		$after = self::names( (string) get_post( $post )->post_content );
		$this->assertSame( array(), array_diff( $after, $names ), 'The edit must not create a block name the stored content did not have.' );
	}


	/**
	 * Attribute shapes that must be filtered, or refused, and never stored raw.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function attribute_shapes(): array {
		$deep = str_repeat( '{"a":', 40 ) . '1' . str_repeat( '}', 40 );

		return array(
			'duplicate keys'           => array( '<!-- wp:paragraph {"a":"1","a":"<img src=x onerror=alert(1)>"} -->' . "\n<p>x</p>\n" . '<!-- /wp:paragraph -->' ),
			'escaped img in a string'  => array( '<!-- wp:paragraph {"label":"<img src=x onerror=alert(1)>"} -->' . "\n<p>x</p>\n" . '<!-- /wp:paragraph -->' ),
			'javascript url'           => array( '<!-- wp:image {"url":"javascript:alert(1)"} -->' . "\n" . '<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure>' . "\n" . '<!-- /wp:image -->' ),
			'deeply nested attributes' => array( '<!-- wp:paragraph {"n":' . $deep . '} -->' . "\n<p>x</p>\n" . '<!-- /wp:paragraph -->' ),
			'array attributes'         => array( '<!-- wp:paragraph [1,2] -->' . "\n<p>x</p>\n" . '<!-- /wp:paragraph -->' ),
			'number attributes'        => array( '<!-- wp:paragraph 5 -->' . "\n<p>x</p>\n" . '<!-- /wp:paragraph -->' ),
		);
	}

	/**
	 * Sent attributes are either refused with the row unchanged, or stored as core's block filter leaves them. The raw value never is.
	 *
	 * @dataProvider attribute_shapes
	 *
	 * @param string $markup The block as sent.
	 */
	public function test_sent_block_attributes_are_stored_as_core_filters_them_or_the_call_is_refused( string $markup ): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );
		// The save's own filter, which is what is stored. filter_block_content() alone keeps a javascript: URL that the save drops.
		$expected = self::first_block_attrs( (string) $this->in_write( static fn(): string => PostWriter::filtered_content( $post, $markup ) ) );

		$result = $this->call(
			$post,
			array(
				array(
					'op'     => 'replace',
					'path'   => '1',
					'name'   => 'core/paragraph',
					'markup' => $markup,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->assertSame( $before, $this->row( $post ) );
			return;
		}

		$stored = (string) get_post( $post )->post_content;
		$this->assertSame( $expected, self::last_block_attrs( $stored ), 'The stored attributes are the ones the save filter gives this user.' );
		$this->assertStringNotContainsString( '<img src=x onerror=alert(1)>', $stored );
	}

	/**
	 * The attributes of the last top-level named block in the content, which is the block the test replaced.
	 *
	 * @param string $content Content.
	 * @return array<string, mixed>
	 */
	private static function last_block_attrs( string $content ): array {
		$attrs = array();
		foreach ( parse_blocks( $content ) as $block ) {
			if ( null !== $block['blockName'] ) {
				$attrs = (array) $block['attrs'];
			}
		}

		return $attrs;
	}

	/**
	 * The attributes of the first named block in the content.
	 *
	 * @param string $content Content.
	 * @return array<string, mixed>
	 */
	private static function first_block_attrs( string $content ): array {
		foreach ( parse_blocks( $content ) as $block ) {
			if ( null !== $block['blockName'] ) {
				return (array) $block['attrs'];
			}
		}

		return array();
	}


	public function test_several_operations_at_different_depths_with_multibyte_text_give_the_exact_expected_content(): void {
		$group = '<!-- wp:group -->' . "\n" . '<div class="wp-block-group">' . self::paragraph( 'In' ) . '</div>' . "\n" . '<!-- /wp:group -->';
		$post  = $this->raw_post( $group . "\n\n" . self::paragraph( 'Root' ) );

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '0.0',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'Ünï 🙂' ),
					),
					array(
						'op'     => 'insert_after',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( '深' ),
					),
					array(
						'op'     => 'append',
						'markup' => self::paragraph( '尾 🙂' ),
					),
				),
			)
		);

		$expected = '<!-- wp:group -->' . "\n" . '<div class="wp-block-group">' . self::paragraph( 'Ünï 🙂' ) . '</div>' . "\n" . '<!-- /wp:group -->'
			. "\n\n" . self::paragraph( 'Root' ) . "\n\n" . self::paragraph( '深' ) . "\n\n" . self::paragraph( '尾 🙂' );
		$this->assertSame( $expected, get_post( $post )->post_content );
	}

	public function test_an_exception_from_a_save_filter_during_the_preview_is_an_error_with_nothing_written_and_the_next_call_works(): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );
		$filter = static function ( string $content ): string {
			if ( str_contains( $content, 'BOOM' ) ) {
				throw new \RuntimeException( 'boom' );
			}

			return $content;
		};
		add_filter( 'content_save_pre', $filter, 20 );

		try {
			$result = $this->call(
				$post,
				array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'BOOM' ),
					),
				)
			);
			$this->assertWPError( $result );
			$this->assertStringNotContainsString( 'boom', (string) $result->get_error_message() );
			$this->assertSame( $before, $this->row( $post ) );

			$out = $this->output_of(
				self::TOOL_NAME,
				array(
					'id'         => $post,
					'operations' => array(
						array(
							'op'     => 'replace',
							'path'   => '1',
							'name'   => 'core/paragraph',
							'markup' => self::paragraph( 'ok' ),
						),
					),
				)
			);
			$this->assertTrue( $out['changed'] );
		} finally {
			remove_filter( 'content_save_pre', $filter, 20 );
		}
	}

	public function test_a_pcre_failure_is_refused_with_nothing_written_and_the_next_call_works(): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );
		$old    = ini_get( 'pcre.backtrack_limit' );

		// With the regex engine out of budget, the change log's query for the refused call gets the wrong number of placeholders.
		// That is reported as a WordPress usage notice, which this test expects.
		$this->setExpectedIncorrectUsage( 'wpdb::prepare' );
		ini_set( 'pcre.backtrack_limit', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restored in finally, for this call only.
		try {
			$result = $this->call(
				$post,
				array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'X' ),
					),
				)
			);
			$this->assertWPError( $result );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value read above.
		}

		$this->assertSame( $before, $this->row( $post ) );
		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'Y' ),
					),
				),
			)
		);
		$this->assertTrue( $out['changed'] );
	}
}
