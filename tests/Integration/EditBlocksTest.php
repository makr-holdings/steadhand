<?php
/**
 * The steadhand/edit-blocks ability through the pipeline: capabilities, object refusals, the guardrails and what they see,
 * the KSES and race refusals, the no-op, the snapshot, the change log, the schema, and a fixing guardrail.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Engine;
use Steadhand\Guardrails\Result;
use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Pipeline\PostWriter;
use Steadhand\Abilities\EditBlocks;
use Steadhand\Abilities\PostFields;

/**
 * Covers the block editing tool end to end. The posts are written through the real write path, so every check the update
 * tool has runs too.
 */
final class EditBlocksTest extends PostWriteTestCase {

	private const TOOL_NAME = 'steadhand-edit-blocks';
	private const ABILITY   = 'steadhand/edit-blocks';

	/**
	 * Two paragraphs, separated by a blank line. Paths 0 and 1.
	 */
	/**
	 * The first paragraph of CONTENT, with the blank line that follows it.
	 */
	private const CONTENT_ONE = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->";

	private const CONTENT = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->";

	/**
	 * The one refusal text every object-level miss gets.
	 */
	private const NOT_FOUND = 'Not found, or this agent may not access it.';

	/**
	 * A paragraph block as the editor writes it.
	 *
	 * @param string $text Paragraph text.
	 */
	private static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * A post by the fixture administrator with the two paragraphs, in the given status.
	 *
	 * @param string               $status Status.
	 * @param array<string, mixed> $fields Overrides.
	 */
	private function fixture( string $status = 'draft', array $fields = array() ): int {
		return $this->admin_post(
			array_merge(
				array(
					'post_status'  => $status,
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Kept excerpt',
					'post_content' => self::CONTENT,
				),
				$fields
			)
		);
	}

	/**
	 * The input that replaces the second paragraph.
	 *
	 * @param int    $post   Post ID.
	 * @param string $markup Replacement markup.
	 * @return array<string, mixed>
	 */
	private function replace_two( int $post, string $markup = '' ): array {
		return array(
			'id'         => $post,
			'operations' => array(
				array(
					'op'     => 'replace',
					'path'   => '1',
					'name'   => 'core/paragraph',
					'markup' => '' === $markup ? self::paragraph( 'Changed' ) : $markup,
				),
			),
		);
	}

	/**
	 * A pipeline whose guardrails are the given rules, for the tests that need a rule of their own.
	 *
	 * @param array<int, \Steadhand\Guardrails\Rule> $first First rules.
	 * @param array<int, \Steadhand\Guardrails\Rule> $last  Last rules.
	 */
	private function pipeline( array $first, array $last ): Pipeline {
		return new Pipeline( new DbLogger(), new Engine( $first, $last ) );
	}

	/**
	 * The digest the change log keeps for text: the SHA-256 in groups of eight hex digits, separated by colons.
	 *
	 * @param string $text Text.
	 */
	private static function digest( string $text ): string {
		return PostFields::group_hex( hash( 'sha256', $text ) );
	}

	/**
	 * Writes a value straight to the post row, as another process would. Used to change the post between the steps of a call.
	 *
	 * @param int    $post    Post ID.
	 * @param string $content Content to store.
	 */
	private function store_content_behind_steadhand( int $post, string $content ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulates a change made by another process.
		clean_post_cache( $post );
	}

	public function test_is_registered_as_a_write_tool_with_the_documented_annotations_and_is_not_public(): void {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability );
		$this->assertFalse( $ability->get_input_schema()['additionalProperties'] );
		$this->assertSame( array( 'id', 'operations' ), $ability->get_input_schema()['required'] );
		$this->assertSame( 1, $ability->get_input_schema()['properties']['operations']['minItems'] );
		$this->assertSame( 20, $ability->get_input_schema()['properties']['operations']['maxItems'] );

		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['idempotent'] );
		$this->assertFalse( (bool) ( $meta['mcp']['public'] ?? false ) );
	}

	public function test_a_draft_block_edit_changes_only_the_named_block_and_no_other_field(): void {
		$post   = $this->fixture();
		$agent  = $this->act_as_agent();
		$before = $this->row( $post );
		$rows   = $this->count_rows( $agent );

		$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post ) );

		$this->assertSame( str_replace( '<p>Two</p>', '<p>Changed</p>', self::CONTENT ), get_post( $post )->post_content );
		$this->assertTrue( $out['changed'] );
		$this->assertFalse( $out['content_modified_on_save'] );
		$this->assertSame( $post, $out['id'] );
		$this->assertSame( 'draft', $out['status'] );

		$after = $this->row( $post );
		foreach ( array( 'post_title', 'post_excerpt', 'post_status', 'post_author', 'post_name', 'post_date_gmt', 'post_type', 'post_parent', 'post_password' ) as $field ) {
			$this->assertSame( $before[ $field ], $after[ $field ], "$field must not change." );
		}

		$this->assertSame( $rows + 1, $this->count_rows( $agent ), 'The call is logged once.' );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'] );
	}

	public function test_every_operation_applies_against_the_stored_paths_in_one_call(): void {
		$one   = self::paragraph( 'One' );
		$two   = self::paragraph( 'Two' );
		$three = self::paragraph( 'Three' );
		$post  = $this->fixture( 'draft', array( 'post_content' => "$one\n\n$two\n\n$three" ) );
		$this->act_as_agent();

		$inserted = self::paragraph( 'Inserted' );
		$appended = self::paragraph( 'Appended' );
		$out      = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '0',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'Uno' ),
					),
					array(
						'op'     => 'insert_before',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => $inserted,
					),
					array(
						'op'   => 'remove',
						'path' => '2',
						'name' => 'core/paragraph',
					),
					array(
						'op'     => 'append',
						'markup' => $appended,
					),
				),
			)
		);

		$this->assertTrue( $out['changed'] );
		$this->assertSame( self::paragraph( 'Uno' ) . "\n\n$inserted\n\n$two\n\n$appended", get_post( $post )->post_content );
	}

	public function test_an_agent_without_either_write_capability_is_refused_and_the_post_is_unchanged(): void {
		$post = $this->fixture();
		$this->act_as_agent( array( Capabilities::WRITE_DRAFTS => false ) );
		$before = $this->row( $post );

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->execute_ability( self::ABILITY, $this->replace_two( $post ) ) ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_write_drafts_alone_cannot_edit_a_published_post_and_publish_alone_can(): void {
		$post = $this->fixture( 'publish' );

		$this->act_as_agent();
		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->execute_ability( self::ABILITY, $this->replace_two( $post ) ) ) );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );

		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );
		$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post ) );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( 'publish', $out['status'] );
	}

	public function test_a_missing_trashed_unreadable_or_disallowed_post_gets_the_same_refusal_text(): void {
		$this->act_as_agent();
		$parent = $this->admin_post();

		$attachment = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Image',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $this->admin_id(),
			)
		);
		$revision   = $this->admin_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $parent,
			)
		);
		$block      = $this->admin_post( array( 'post_type' => 'wp_block' ) );

		$cases = array(
			'missing'    => 999999999,
			'trashed'    => $this->fixture( 'trash' ),
			'attachment' => $attachment,
			'revision'   => $revision,
			'wp_block'   => $block,
		);

		$texts = array();
		foreach ( $cases as $case => $id ) {
			$texts[ $case ] = $this->refusal_text( $this->tool_result( self::TOOL_NAME, $this->replace_two( $id ) ) );
		}

		$this->assertCount( 1, array_unique( array_values( $texts ) ), 'Every refusal must read the same: ' . wp_json_encode( $texts ) );
		$this->assertStringContainsString( self::NOT_FOUND, $texts['missing'] );

		// A private post the agent can read is the one case that names the missing capability (see RefusalDetailTest).
		$private = $this->fixture( 'private' );
		$this->assertSame(
			'This post is published, scheduled or private. Changing it needs the steadhand_publish capability, which this agent does not hold. Nothing was changed.',
			$this->refusal_text( $this->tool_result( self::TOOL_NAME, $this->replace_two( $private ) ) )
		);
	}

	public function test_an_administrator_without_steadhand_capabilities_is_refused(): void {
		$post = $this->fixture();
		wp_set_current_user( $this->admin_id() );

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->execute_ability( self::ABILITY, $this->replace_two( $post ) ) ) );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
	}

	public function test_an_edit_lock_held_by_another_user_blocks_the_edit(): void {
		$post  = $this->fixture();
		$human = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent = $this->act_as_agent();
		update_post_meta( $post, '_edit_lock', time() . ':' . $human );
		$before = $this->row( $post );

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $this->execute_ability( self::ABILITY, $this->replace_two( $post ) ) ) );
		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	public function test_content_over_the_size_limit_after_an_insert_is_blocked_and_nothing_is_written(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_guardrail_blocked',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'         => $post,
						'operations' => array(
							array(
								'op'     => 'append',
								'markup' => self::paragraph( str_repeat( 'a', 262000 ) ),
							),
						),
					)
				)
			)
		);
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_an_insert_that_leaves_a_block_open_is_refused_as_invalid_input_with_nothing_written(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_invalid_input',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'         => $post,
						'operations' => array(
							array(
								'op'     => 'insert_after',
								'path'   => '1',
								'name'   => 'core/paragraph',
								'markup' => '<!-- /wp:group -->',
							),
						),
					)
				)
			)
		);
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_normal_edit_is_stored_exactly_as_spliced_and_reports_no_save_change(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$expected = str_replace( '<p>Two</p>', '<p>Changed</p>', self::CONTENT );

		$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post ) );

		$this->assertFalse( $out['content_modified_on_save'] );
		$this->assertSame( $expected, get_post( $post )->post_content, 'The stored content must equal the spliced content, byte for byte.' );
	}

	/**
	 * Stores $admin as the first block of the post, and a paragraph the agent will edit after it. Checks that an edit to the
	 * other block leaves every byte of $admin as it is, with no flag, and that the stored row is what was spliced.
	 *
	 * @param string $admin  Bytes an administrator stored, not sent by the agent.
	 * @param string $label  Label for failures.
	 * @param bool   $tags   Whether tag balancing is on for the save.
	 */
	private function assert_edit_of_another_block_keeps_the_bytes( string $admin, string $label, bool $tags = false ): void {
		if ( $tags ) {
			update_option( 'use_balanceTags', 1 );
		}

		try {
			$post = $this->fixture();
			$this->store_content_behind_steadhand( $post, $admin . "\n\n" . self::paragraph( 'Two' ) );
			$this->act_as_agent();
			$before = $this->row( $post );

			$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post ) );

			$this->assertFalse( $out['content_modified_on_save'], $label );
			$this->assertSame( $admin . "\n\n" . self::paragraph( 'Changed' ), get_post( $post )->post_content, "$label: the stored bytes of the untouched block must survive." );
			$after = $this->row( $post );
			foreach ( array( 'post_title', 'post_excerpt', 'post_status', 'post_author', 'post_name' ) as $field ) {
				$this->assertSame( $before[ $field ], $after[ $field ], "$label: $field must not change." );
			}
		} finally {
			if ( $tags ) {
				delete_option( 'use_balanceTags' );
			}
		}
	}

	public function test_a_block_edit_keeps_an_image_block_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes(
			'<!-- wp:image {"id":12,"sizeSlug":"large","linkDestination":"none"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="https://example.com/a.jpg" alt="Salt & pepper" class="wp-image-12"/></figure>' . "\n" . '<!-- /wp:image -->',
			'image'
		);
	}

	public function test_a_block_edit_keeps_a_gallery_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes(
			'<!-- wp:gallery {"ids":[1,2],"linkTo":"none"} -->' . "\n" . '<figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":1,"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="https://example.com/1.jpg" alt="" class="wp-image-1"/></figure>' . "\n" . '<!-- /wp:image --></figure>' . "\n" . '<!-- /wp:gallery -->',
			'gallery'
		);
	}

	public function test_a_block_edit_keeps_a_cover_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes(
			'<!-- wp:cover {"url":"https://example.com/c.jpg","id":3,"dimRatio":50} -->' . "\n" . '<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-3" alt="" src="https://example.com/c.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph -->' . "\n" . '<p>Hi</p>' . "\n" . '<!-- /wp:paragraph --></div></div>' . "\n" . '<!-- /wp:cover -->',
			'cover'
		);
	}

	public function test_a_block_edit_keeps_a_separator_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes(
			'<!-- wp:separator -->' . "\n" . '<hr class="wp-block-separator has-alpha-channel-opacity"/>' . "\n" . '<!-- /wp:separator -->',
			'separator'
		);
	}

	public function test_a_block_edit_keeps_an_embed_with_an_ampersand_in_its_url_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes(
			'<!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc&t=10","type":"video","providerNameSlug":"youtube"} -->' . "\n" . '<figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . 'https://www.youtube.com/watch?v=abc&t=10' . "\n" . '</div></figure>' . "\n" . '<!-- /wp:embed -->',
			'embed'
		);
	}

	public function test_a_block_edit_keeps_an_ampersand_in_block_metadata_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes(
			'<!-- wp:group {"metadata":{"name":"Hero & intro"}} -->' . "\n" . '<div class="wp-block-group"><!-- wp:paragraph -->' . "\n" . '<p>Hi</p>' . "\n" . '<!-- /wp:paragraph --></div>' . "\n" . '<!-- /wp:group -->',
			'metadata'
		);
	}

	public function test_a_block_edit_keeps_a_mark_with_a_style_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes(
			'<!-- wp:paragraph -->' . "\n" . '<p><mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color">Marked</mark></p>' . "\n" . '<!-- /wp:paragraph -->',
			'mark'
		);
	}

	public function test_a_block_edit_keeps_an_image_with_srcset_and_sizes_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes(
			'<!-- wp:image -->' . "\n" . '<figure class="wp-block-image"><img src="https://example.com/a.jpg" srcset="https://example.com/a-300.jpg 300w, https://example.com/a-600.jpg 600w" sizes="(max-width: 600px) 100vw, 600px" alt=""/></figure>' . "\n" . '<!-- /wp:image -->',
			'srcset'
		);
	}

	public function test_a_block_edit_keeps_an_administrator_script_in_a_custom_html_block_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes( '<!-- wp:html -->' . "\n" . '<script>var x = 1 < 2;</script>' . "\n" . '<!-- /wp:html -->', 'script' );
	}

	public function test_a_block_edit_keeps_an_administrator_iframe_in_a_custom_html_block_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes( '<!-- wp:html -->' . "\n" . '<iframe src="https://example.com/frame"></iframe>' . "\n" . '<!-- /wp:html -->', 'iframe' );
	}

	public function test_a_block_edit_keeps_custom_css_on_a_block_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes( '<!-- wp:paragraph {"style":{"css":"color:red"}} -->' . "\n<p>Kept</p>\n" . '<!-- /wp:paragraph -->', 'custom css' );
	}

	public function test_a_block_edit_keeps_an_invalid_entity_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes( '<!-- wp:paragraph -->' . "\n<p>Euro &#128;</p>\n" . '<!-- /wp:paragraph -->', 'entity' );
	}

	public function test_a_block_edit_keeps_a_bare_ampersand_in_text_byte_for_byte(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes( '<!-- wp:paragraph -->' . "\n<p>Tom & Jerry</p>\n" . '<!-- /wp:paragraph -->', 'bare ampersand' );
	}

	public function test_a_block_edit_keeps_unclosed_classic_html_at_the_root_byte_for_byte_with_tag_balancing_on(): void {
		$this->assert_edit_of_another_block_keeps_the_bytes( '<div><p>Classic', 'unclosed root', true );
	}

	public function test_removing_the_block_that_holds_an_administrator_script_works_and_leaves_the_rest(): void {
		$post = $this->fixture();
		$this->store_content_behind_steadhand( $post, '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->' . "\n\n" . self::paragraph( 'Two' ) );
		$this->act_as_agent();

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'   => 'remove',
						'path' => '0',
						'name' => 'core/html',
					),
				),
			)
		);

		$this->assertSame( "\n\n" . self::paragraph( 'Two' ), get_post( $post )->post_content );
	}

	public function test_replacing_the_block_that_holds_an_administrator_script_works(): void {
		$post = $this->fixture();
		$this->store_content_behind_steadhand( $post, '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->' . "\n\n" . self::paragraph( 'Two' ) );
		$this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '0',
						'name'   => 'core/html',
						'markup' => self::paragraph( 'Replaced' ),
					),
				),
			)
		);

		$this->assertFalse( $out['content_modified_on_save'] );
		$this->assertSame( self::paragraph( 'Replaced' ) . "\n\n" . self::paragraph( 'Two' ), get_post( $post )->post_content );
	}

	public function test_a_script_sent_is_removed_from_what_is_stored_the_rest_is_kept_and_the_call_is_flagged(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$before = $this->row( $post );

		$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post, "<!-- wp:paragraph -->\n<p>Hi<script>alert(1)</script></p>\n<!-- /wp:paragraph -->" ) );

		$this->assertTrue( $out['content_modified_on_save'] );
		$this->assertStringContainsString( 'WordPress changed part of the markup you sent', $out['warnings'][0] );
		$this->assertSame( self::CONTENT_ONE . "\n\n<!-- wp:paragraph -->\n<p>Hialert(1)</p>\n<!-- /wp:paragraph -->", get_post( $post )->post_content );
		$this->assertStringNotContainsString( '<script', get_post( $post )->post_content );
		$this->assertSame( $before['post_title'], $this->row( $post )['post_title'] );
	}

	public function test_a_sent_image_is_stored_in_the_filtered_form_and_the_call_is_flagged(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$image = '<!-- wp:image -->' . "\n" . '<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure>' . "\n" . '<!-- /wp:image -->';

		$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post, $image ) );

		$this->assertTrue( $out['content_modified_on_save'] );
		$this->assertSame( self::CONTENT_ONE . "\n\n" . str_replace( 'alt=""/>', 'alt="" />', $image ), get_post( $post )->post_content );
	}

	public function test_clean_sent_markup_is_not_flagged_and_is_stored_exactly_as_spliced(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$markup = '<!-- wp:paragraph -->' . "\n" . '<p>Plain <strong>text</strong> &amp; more</p>' . "\n" . '<!-- /wp:paragraph -->';

		$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post, $markup ) );

		$this->assertFalse( $out['content_modified_on_save'] );
		$this->assertSame( array(), $out['warnings'] );
		$this->assertSame( self::CONTENT_ONE . "\n\n" . $markup, get_post( $post )->post_content );
	}

	public function test_an_unclosed_div_sent_with_tag_balancing_on_is_stored_balanced_and_flagged(): void {
		update_option( 'use_balanceTags', 1 );
		try {
			$post = $this->fixture();
			$this->act_as_agent();

			$out = $this->output_of(
				self::TOOL_NAME,
				array(
					'id'         => $post,
					'operations' => array(
						array(
							'op'     => 'insert_after',
							'path'   => '1',
							'name'   => 'core/paragraph',
							'markup' => "<div>\n<p>Open",
						),
					),
				)
			);

			$this->assertTrue( $out['content_modified_on_save'] );
			$this->assertStringContainsString( '</p></div>', get_post( $post )->post_content );
		} finally {
			delete_option( 'use_balanceTags' );
		}
	}

	public function test_two_sent_pieces_that_end_up_next_to_each_other_are_filtered_as_one_run(): void {
		$one  = self::paragraph( 'One' );
		$two  = self::paragraph( 'Two' );
		$post = $this->fixture( 'draft', array( 'post_content' => $one . $two ) );
		$this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'insert_after',
						'path'   => '0',
						'name'   => 'core/paragraph',
						'markup' => '<scr',
					),
					array(
						'op'     => 'insert_before',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => 'ipt>alert(1)</script>',
					),
				),
			)
		);

		$run = $this->in_write( static fn(): string => PostWriter::filtered_content( $post, "\n\n<scr\n\nipt>alert(1)</script>\n\n" ) );
		$this->assertStringNotContainsString( '<script', get_post( $post )->post_content );
		$this->assertSame( $one . $run . $two, get_post( $post )->post_content, 'The two pieces are one run, filtered together.' );
		$this->assertTrue( $out['content_modified_on_save'] );
	}

	public function test_two_appends_are_filtered_as_one_run_and_no_script_is_stored(): void {
		$post = $this->fixture();
		$this->act_as_agent();

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'append',
						'markup' => '<scr',
					),
					array(
						'op'     => 'append',
						'markup' => 'ipt>alert(1)</script>',
					),
				),
			)
		);

		$stored = get_post( $post )->post_content;
		$this->assertStringNotContainsString( '<script', $stored );
		$run = $this->in_write( static fn(): string => PostWriter::filtered_content( $post, "\n\n<scr\n\nipt>alert(1)</script>" ) );
		$this->assertSame( self::CONTENT . $run, $stored );
	}

	public function test_an_unterminated_comment_sent_before_a_block_is_refused_and_the_row_is_unchanged(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_invalid_input',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'         => $post,
						'operations' => array(
							array(
								'op'     => 'insert_before',
								'path'   => '1',
								'name'   => 'core/paragraph',
								'markup' => '<!--',
							),
						),
					)
				)
			)
		);
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_piece_that_is_not_complete_blocks_is_refused_as_invalid_input_with_nothing_written(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_invalid_input',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'         => $post,
						'operations' => array(
							array(
								'op'     => 'insert_after',
								'path'   => '1',
								'name'   => 'core/paragraph',
								'markup' => '<!-- /wp:group -->',
							),
						),
					)
				)
			)
		);
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_the_guardrails_see_exactly_the_bytes_that_are_stored(): void {
		$admin = '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->';
		$post  = $this->fixture();
		$this->store_content_behind_steadhand( $post, $admin . "\n\n" . self::paragraph( 'Two' ) );
		$this->act_as_agent();
		$seen = null;
		$last = new FakeRule(
			'steadhand/recorder',
			static function ( Context $context ) use ( &$seen ): Result {
				$seen = $context->content;

				return Result::allow();
			}
		);

		$result = $this->pipeline( array(), array( $last ) )->run(
			new EditBlocks(),
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'insert_after',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => "<script>alert(1)</script>\n<!-- wp:paragraph -->\n<p>Added</p>\n<!-- /wp:paragraph -->",
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'content' => get_post( $post )->post_content ), $seen );
		$this->assertStringStartsWith( $admin, get_post( $post )->post_content );
	}

	public function test_a_change_made_to_an_untouched_block_by_another_plugin_during_the_save_is_reported(): void {
		$post = $this->fixture();
		$this->store_content_behind_steadhand( $post, "<!-- wp:paragraph -->\n<p>Kept</p>\n<!-- /wp:paragraph -->\n\n" . self::paragraph( 'Two' ) );
		$this->act_as_agent();
		$done     = false;
		$listener = static function ( int $saved ) use ( $post, &$done ): void {
			if ( $saved !== $post || $done ) {
				return;
			}
			$done = true;
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => str_replace( 'Kept', 'Late', (string) get_post( $post )->post_content ) ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A late change made by another process during the save.
			clean_post_cache( $post );
		};
		add_action( 'save_post', $listener );

		try {
			$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post ) );
		} finally {
			remove_action( 'save_post', $listener );
		}

		$this->assertTrue( $done );
		$this->assertTrue( $out['content_modified_on_save'] );
		$this->assertStringContainsString( '<p>Late</p>', get_post( $post )->post_content );
	}

	public function test_the_helper_that_checks_the_save_refuses_to_run_outside_a_write(): void {
		$post = $this->fixture();

		$this->expectException( \LogicException::class );
		PostWriter::filtered_content( $post, self::CONTENT );
	}

	public function test_a_snapshot_without_a_guardrail_read_is_refused_and_nothing_is_written(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$input   = $this->replace_two( $post );
		$ability = new EditBlocks();
		$revs    = $this->revision_ids( $post );

		$result = $this->in_write(
			static function () use ( $ability, $input ): array {
				$snapshot = $ability->snapshot( $input );
				$output   = $ability->execute( $input );

				return array( $snapshot, $output );
			}
		);

		$this->assertSame(
			array(
				'id'       => 0,
				'undoable' => false,
			),
			$result[0]
		);
		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result[1] ) );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
		$this->assertSame( $revs, $this->revision_ids( $post ) );
	}

	public function test_guarded_content_throws_for_an_unreadable_post_instead_of_returning_nothing(): void {
		$post = $this->fixture( 'trash' );
		$this->act_as_agent();

		$this->expectException( \RuntimeException::class );
		( new EditBlocks() )->guarded_content( $this->replace_two( $post ) );
	}

	public function test_a_post_that_became_unreadable_after_the_snapshot_is_refused_as_not_found(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$done = false;

		add_filter(
			'wp_revisions_to_keep',
			function ( $keep ) use ( $post, &$done ) {
				if ( ! $done ) {
					$done = true;
					global $wpdb;
					$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trashes the post between the steps.
					clean_post_cache( $post );
				}

				return $keep;
			}
		);

		$this->assertSame( 'steadhand_not_found', $this->refusal_code( $this->execute_ability( self::ABILITY, $this->replace_two( $post ) ) ) );
		$this->assertTrue( $done );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
	}

	public function test_a_second_execute_without_a_snapshot_has_no_plan_left_to_write(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$input   = $this->replace_two( $post );
		$ability = new EditBlocks();

		$result = $this->in_write(
			static function () use ( $ability, $input ): array {
				$ability->guarded_content( $input );
				$ability->snapshot( $input );
				$first  = $ability->execute( $input );
				$second = $ability->execute( $input );

				return array( $first, $second );
			}
		);

		$this->assertTrue( $result[0]['changed'] );
		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result[1] ) );
	}

	public function test_a_plan_serves_one_execute_even_when_the_call_changes_nothing(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$input   = $this->replace_two( $post, self::paragraph( 'Two' ) );
		$ability = new EditBlocks();

		$result = $this->in_write(
			static function () use ( $ability, $input ): array {
				$ability->guarded_content( $input );
				$ability->snapshot( $input );
				$first  = $ability->execute( $input );
				$second = $ability->execute( $input );

				return array( $first, $second );
			}
		);

		$this->assertFalse( $result[0]['changed'] );
		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result[1] ) );
	}

	public function test_a_snapshot_without_a_fresh_guardrail_read_does_not_use_an_earlier_one(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$input   = $this->replace_two( $post );
		$ability = new EditBlocks();

		$result = $this->in_write(
			static function () use ( $ability, $input ): array {
				$ability->guarded_content( $input );
				$ability->snapshot( $input );
				$ability->snapshot( $input );

				return array( $ability->execute( $input ) );
			}
		);

		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result[0] ) );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
	}

	public function test_a_stale_plan_from_an_earlier_call_is_dropped_by_the_next_guardrail_read(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$input   = $this->replace_two( $post );
		$ability = new EditBlocks();

		$result = $this->in_write(
			static function () use ( $ability, $input ): array {
				$ability->guarded_content( $input );
				$ability->snapshot( $input );
				$ability->guarded_content( $input );

				return array( $ability->execute( $input ) );
			}
		);

		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result[0] ) );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
	}

	public function test_the_post_is_read_again_before_the_snapshot_so_a_change_made_without_a_cache_clear_is_refused_with_no_revision(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$revs    = $this->revision_ids( $post );
		$changed = self::paragraph( 'Changed behind the cache' );
		$first   = new FakeRule(
			'steadhand/raw-write',
			function ( Context $context ) use ( $post, $changed ): Result {
				unset( $context );
				global $wpdb;
				$wpdb->update( $wpdb->posts, array( 'post_content' => $changed ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Changes the row without clearing the cache, as another process could.

				return Result::allow();
			}
		);

		$result = $this->pipeline( array( $first ), array() )->run( new EditBlocks(), $this->replace_two( $post ) );

		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result ) );
		$this->assertSame( $changed, get_post( $post )->post_content );
		$this->assertSame( $revs, $this->revision_ids( $post ), 'A refused call takes no revision.' );
	}

	public function test_a_post_changed_after_the_guardrails_read_it_is_refused_and_nothing_is_written(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$changed   = self::paragraph( 'Written by someone else' );
		$first     = new FakeRule(
			'steadhand/other-process',
			function ( Context $context ) use ( $post, $changed ): Result {
				unset( $context );
				$this->store_content_behind_steadhand( $post, $changed );

				return Result::allow();
			}
		);
		$revisions = $this->revision_ids( $post );

		$result = $this->pipeline( array( $first ), array() )->run( new EditBlocks(), $this->replace_two( $post ) );

		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result ) );
		$this->assertSame( $changed, get_post( $post )->post_content, 'The other process change must stay.' );
		$this->assertSame( $revisions, $this->revision_ids( $post ), 'Nothing is snapshotted for a refused call.' );
	}

	public function test_a_post_changed_between_the_snapshot_and_the_write_is_refused_and_the_change_stays(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$changed = self::paragraph( 'Written between snapshot and write' );
		$done    = false;

		// wp_revisions_to_keep() is called by update-post's snapshot, after the content was read and checked.
		add_filter(
			'wp_revisions_to_keep',
			function ( $keep ) use ( $post, $changed, &$done ) {
				if ( ! $done ) {
					$done = true;
					$this->store_content_behind_steadhand( $post, $changed );
				}

				return $keep;
			}
		);

		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $this->execute_ability( self::ABILITY, $this->replace_two( $post ) ) ) );
		$this->assertTrue( $done, 'The change must have happened between the steps.' );
		$this->assertSame( $changed, get_post( $post )->post_content );
	}

	public function test_a_no_op_edit_reports_no_change_takes_no_revision_and_logs_one_row(): void {
		$post   = $this->fixture();
		$agent  = $this->act_as_agent();
		$before = $this->revision_ids( $post );

		$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post, self::paragraph( 'Two' ) ) );

		$this->assertFalse( $out['changed'] );
		$this->assertSame( $before, $this->revision_ids( $post ) );
		$this->assertSame( 0, (int) $this->latest_row( $agent )['snapshot_id'] );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'] );
	}

	public function test_a_change_takes_a_revision_of_the_content_before_and_the_row_points_at_it(): void {
		$post  = $this->fixture();
		$agent = $this->act_as_agent();

		$out = $this->output_of( self::TOOL_NAME, $this->replace_two( $post ) );

		$this->assertSame( array(), $out['warnings'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 1, (int) $row['undoable'] );

		$revision = (int) $row['snapshot_id'];
		$this->assertGreaterThan( 0, $revision );
		$this->assertSame( self::CONTENT, get_post( $revision )->post_content );
	}

	public function test_the_log_keeps_a_digest_of_the_markup_and_never_its_text(): void {
		$post   = $this->fixture();
		$agent  = $this->act_as_agent();
		$marker = 'MARKER-' . strtolower( wp_generate_password( 12, false ) );
		$markup = self::paragraph( $marker . ' with some text' );

		$this->output_of( self::TOOL_NAME, $this->replace_two( $post, $markup ) );

		$row = $this->latest_row( $agent );
		$this->assertStringNotContainsString( $marker, (string) $row['input'] );
		$this->assertStringNotContainsString( 'some text', (string) $row['input'] );
		$input = json_decode( (string) $row['input'], true );
		$this->assertSame( $post, $input['id'] );
		$this->assertSame( strlen( $markup ), $input['operations'][0]['markup']['bytes'] );
		$this->assertSame( self::digest( $markup ), $input['operations'][0]['markup']['sha256'] );
		$this->assertSame( 'replace', $input['operations'][0]['op'] );
		$this->assertSame( '1', $input['operations'][0]['path'] );
		$this->assertSame( 'core/paragraph', $input['operations'][0]['name'] );
	}

	public function test_a_refused_call_logs_no_markup_text_either(): void {
		$post   = $this->fixture();
		$agent  = $this->act_as_agent();
		$marker = 'MARKER-' . strtolower( wp_generate_password( 12, false ) );

		$this->refusal_code(
			$this->execute_ability(
				self::ABILITY,
				array(
					'id'         => $post,
					'operations' => array(
						array(
							'op'     => 'remove',
							'path'   => '1',
							'name'   => 'core/paragraph',
							'markup' => self::paragraph( $marker ),
						),
						array(
							'op'   => 'append',
							'text' => self::paragraph( $marker . '-2' ),
						),
					),
				)
			)
		);

		$row = $this->latest_row( $agent );
		$this->assertSame( 'blocked', $row['result'] );
		$this->assertStringNotContainsString( $marker, (string) $row['input'] );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
	}

	/**
	 * Calls that break the schema or the input rules. Each is refused before anything is written, and logged as blocked with
	 * no markup text.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function refused_inputs(): array {
		$markup = self::paragraph( 'x' );
		$ok     = array(
			'op'     => 'replace',
			'path'   => '1',
			'name'   => 'core/paragraph',
			'markup' => $markup,
		);

		$cases = array(
			'an extra property'              => array(
				'id'         => 1,
				'operations' => array( $ok ),
				'title'      => 'x',
			),
			'no operations'                  => array(
				'id'         => 1,
				'operations' => array(),
			),
			'twenty-one operations'          => array(
				'id'         => 1,
				'operations' => array_fill(
					0,
					21,
					array(
						'op'     => 'append',
						'markup' => $markup,
					)
				),
			),
			'a path with a leading zero'     => array(
				'id'         => 1,
				'operations' => array( array_merge( $ok, array( 'path' => '01' ) ) ),
			),
			'markup on remove'               => array(
				'id'         => 1,
				'operations' => array(
					array(
						'op'     => 'remove',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => $markup,
					),
				),
			),
			'a path on append'               => array(
				'id'         => 1,
				'operations' => array(
					array(
						'op'     => 'append',
						'path'   => '1',
						'markup' => $markup,
					),
				),
			),
			'no name on replace'             => array(
				'id'         => 1,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'markup' => $markup,
					),
				),
			),
			'an op that does not exist'      => array(
				'id'         => 1,
				'operations' => array( array_merge( $ok, array( 'op' => 'move' ) ) ),
			),
			'operations that are not a list' => array(
				'id'         => 1,
				'operations' => 'replace',
			),
		);

		return array_map( static fn( array $input ): array => array( $input ), $cases );
	}

	/**
	 * Input the schema or the input rules refuse: nothing is written, and the call is logged as blocked with no markup text.
	 *
	 * @dataProvider refused_inputs
	 *
	 * @param array<string, mixed> $input Input the schema or the input rules refuse.
	 */
	public function test_input_the_schema_or_the_input_rules_refuse_changes_nothing_and_logs_no_markup( array $input ): void {
		$post        = $this->fixture();
		$agent       = $this->act_as_agent();
		$input['id'] = $post;
		$before      = $this->row( $post );
		$rows        = $this->count_rows( $agent );

		$result = $this->execute_ability( self::ABILITY, $input );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( $rows + 1, $this->count_rows( $agent ), 'A refused call is logged once.' );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertStringNotContainsString( '<!-- wp:paragraph', (string) $this->latest_row( $agent )['input'] );
	}

	public function test_log_input_accepts_any_value_in_any_field_and_keeps_no_markup_text(): void {
		$marker = 'MARKER-' . strtolower( wp_generate_password( 12, false ) );
		$logged = ( new EditBlocks() )->log_input(
			array(
				'id'         => $marker,
				'title'      => $marker,
				'content'    => $marker,
				'operations' => array(
					"not an object $marker",
					array(
						'op'      => 'replace',
						'path'    => '1',
						'name'    => "$marker with spaces",
						'markup'  => 1234,
						'unknown' => $marker,
					),
					array(
						'op'     => array( $marker ),
						'path'   => "$marker/1",
						'name'   => null,
						'markup' => $marker,
					),
				),
			)
		);

		$json = (string) wp_json_encode( $logged );
		$this->assertStringNotContainsString( $marker, $json );
		$this->assertSame( PostFields::digest( "not an object $marker" ), $logged['operations'][0] );
		$this->assertSame( PostFields::digest( $marker ), $logged['id'] );
		$this->assertSame(
			array(
				'bytes' => 0,
				'type'  => 'integer',
			),
			$logged['operations'][1]['markup']
		);
		$this->assertSame( '1', $logged['operations'][1]['path'] );
		$this->assertArrayNotHasKey( 'name', $logged['operations'][1], 'A name that is not a block name is not kept as text.' );
		$this->assertSame(
			array( PostFields::digest( "$marker with spaces" ), PostFields::digest( $marker ) ),
			$logged['operations'][1]['unexpected']
		);
		$this->assertSame( PostFields::digest( $marker ), $logged['operations'][2]['markup'] );
		$this->assertNull( $logged['operations'][2]['name'] );
		$this->assertSame(
			array(
				array(
					'bytes' => 0,
					'type'  => 'array',
				),
				PostFields::digest( "$marker/1" ),
			),
			$logged['operations'][2]['unexpected']
		);
	}

	public function test_the_guardrails_see_the_whole_resulting_content(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$seen = null;
		$last = new FakeRule(
			'steadhand/recorder',
			static function ( Context $context ) use ( &$seen ): Result {
				$seen = $context->content;

				return Result::allow();
			}
		);

		$result = $this->pipeline( array(), array( $last ) )->run( new EditBlocks(), $this->replace_two( $post ) );

		$expected = str_replace( '<p>Two</p>', '<p>Changed</p>', self::CONTENT );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'content' => $expected ), $seen );
		$this->assertSame( $expected, get_post( $post )->post_content );
	}

	public function test_when_the_operations_cannot_be_applied_the_rules_see_each_markup_and_nothing_is_written(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$seen                           = null;
		$last                           = new FakeRule(
			'steadhand/recorder',
			static function ( Context $context ) use ( &$seen ): Result {
				$seen = $context->content;

				return Result::allow();
			}
		);
		$input                          = $this->replace_two( $post );
		$input['operations'][0]['name'] = 'core/heading';

		$result = $this->pipeline( array(), array( $last ) )->run( new EditBlocks(), $input );

		$this->assertSame( 'steadhand_refused_block_mismatch', $this->refusal_code( $result ) );
		$this->assertSame( array( 'operations.0.markup' => self::paragraph( 'Changed' ) ), $seen );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
	}

	public function test_a_fix_that_adds_a_content_key_is_refused_so_content_the_rules_did_not_see_is_never_written(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$fix = new FakeRule(
			'steadhand/rewrite',
			static function ( Context $context ): Result {
				$input            = $context->input;
				$input['content'] = '<p>Not seen by the rules</p>';

				return Result::fix( $input, 'Added content.' );
			}
		);

		$result = $this->pipeline( array( $fix ), array() )->run( new EditBlocks(), $this->replace_two( $post ) );

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
	}

	public function test_a_fix_that_writes_unbalanced_markup_is_caught_by_the_content_rules_and_nothing_is_written(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$fix = new FakeRule(
			'steadhand/rewrite',
			static function ( Context $context ): Result {
				$input                            = $context->input;
				$input['operations'][0]['markup'] = '<!-- /wp:group -->';

				return Result::fix( $input, 'Rewrote the markup.' );
			}
		);

		$result = $this->pipeline( array( $fix ), array( new \Steadhand\Guardrails\Rules\BlockMarkup() ) )->run( new EditBlocks(), $this->replace_two( $post ) );

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
	}

	public function test_a_fix_is_written_only_as_the_content_the_last_rules_saw(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$fix  = new FakeRule(
			'steadhand/rewrite',
			static function ( Context $context ): Result {
				$input                            = $context->input;
				$input['operations'][0]['markup'] = self::paragraph( 'Fixed by a rule' );

				return Result::fix( $input, 'Rewrote the markup.' );
			}
		);
		$seen = null;
		$last = new FakeRule(
			'steadhand/recorder',
			static function ( Context $context ) use ( &$seen ): Result {
				$seen = $context->content;

				return Result::allow();
			}
		);

		$result = $this->pipeline( array( $fix ), array( $last ) )->run( new EditBlocks(), $this->replace_two( $post ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'content' => str_replace( '<p>Two</p>', '<p>Fixed by a rule</p>', self::CONTENT ) ), $seen );
		$this->assertSame( $seen['content'], get_post( $post )->post_content, 'The written content is the content the rules saw.' );
	}

	public function test_a_fix_that_moves_the_call_to_another_post_is_refused_and_neither_post_changes(): void {
		$post  = $this->fixture();
		$other = $this->fixture( 'draft', array( 'post_content' => self::CONTENT ) );
		$this->act_as_agent();
		$fix = new FakeRule(
			'steadhand/move',
			static function ( Context $context ) use ( $other ): Result {
				$input       = $context->input;
				$input['id'] = $other;

				return Result::fix( $input, 'Moved the call.' );
			}
		);

		$result = $this->pipeline( array( $fix ), array() )->run( new EditBlocks(), $this->replace_two( $post ) );

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertSame( self::CONTENT, get_post( $post )->post_content );
		$this->assertSame( self::CONTENT, get_post( $other )->post_content );
	}

	public function test_a_write_that_names_a_block_name_the_post_does_not_have_changes_nothing(): void {
		$post = $this->fixture();
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_refused_block_mismatch',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'         => $post,
						'operations' => array(
							array(
								'op'   => 'remove',
								'path' => '1',
								'name' => 'core/heading',
							),
						),
					)
				)
			)
		);
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_post_that_cannot_be_read_as_blocks_is_refused_as_unsupported_and_nothing_is_written(): void {
		$post = $this->fixture();
		// WordPress would close the comment on save, so the unbalanced content is written straight to the row.
		$this->store_content_behind_steadhand( $post, "<!-- wp:paragraph -->\n<p>Open</p>" );
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_refused_unsupported_markup',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'         => $post,
						'operations' => array(
							array(
								'op'     => 'append',
								'markup' => self::paragraph( 'x' ),
							),
						),
					)
				)
			)
		);
		$this->assertSame( $before, $this->row( $post ) );
	}
}
