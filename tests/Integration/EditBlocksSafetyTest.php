<?php
/**
 * The safety layers of steadhand/edit-blocks: complete blocks per operation, the balanced sent run, the structural check of the
 * assembled content, open HTML before a sent run, the content check between preview and write, revisions that mirror the row,
 * the save filters Steadhand puts in place, and the empty post.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\BlockEditor;
use Steadhand\Pipeline\PostWriter;

/**
 * Each layer has a test that fails when only that layer is removed. Stored content is written straight to the row, as
 * another process would leave it.
 */
final class EditBlocksSafetyTest extends PostWriteTestCase {

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
	 * Stores raw content on a post, without any filter.
	 *
	 * @param int    $post Parameter.
	 * @param string $content Parameter.
	 */
	private function store_raw( int $post, string $content ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stores the fixture as another process would leave it.
		clean_post_cache( $post );
	}

	/**
	 * A draft by the fixture administrator, with the given raw content and an agent ready to edit it.
	 *
	 * @param string $content Parameter.
	 * @return int
	 */
	private function raw_post( string $content ): int {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'placeholder',
			)
		);
		$this->store_raw( $post, $content );
		$this->act_as_agent();

		return $post;
	}

	/**
	 * Replaces the block at a path with markup, as an operation.
	 *
	 * @param int    $post Parameter.
	 * @param string $path Parameter.
	 * @param string $name Parameter.
	 * @param string $markup Parameter.
	 * @return mixed
	 */
	private function replace( int $post, string $path, string $name, string $markup ): mixed {
		return $this->execute_ability(
			self::ABILITY,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => $path,
						'name'   => $name,
						'markup' => $markup,
					),
				),
			)
		);
	}

	/**
	 * Forces a content_save_pre filter for one test, and removes it afterwards.
	 *
	 * @param callable $filter Parameter.
	 * @param callable $work Parameter.
	 */
	private function with_save_filter( callable $filter, callable $work ): void {
		add_filter( 'content_save_pre', $filter, 20 );
		try {
			$work();
		} finally {
			remove_filter( 'content_save_pre', $filter, 20 );
		}
	}

	public function test_the_proof_of_concept_split_of_a_group_is_refused_before_anything_is_written(): void {
		$group  = "<!-- wp:group -->\n" . self::paragraph( 'x' ) . "\n<!-- /wp:group -->";
		$post   = $this->raw_post( $group );
		$before = $this->row( $post );
		$markup = '<!-- /wp:group --><!-- wp:probe/echo {"label":"<img src=x onerror=alert(1)>"} /--><!-- wp:template-part {"slug":"header","tagName":"script"} /--><!-- wp:group -->';

		$result = $this->replace( $post, '0.0', 'core/paragraph', $markup );

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_run_the_save_filter_leaves_unbalanced_is_refused_with_the_layer_two_message(): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );
		$filter = static fn( string $content ): string => str_contains( $content, 'UNBAL' ) ? $content . '<!-- /wp:group -->' : $content;

		$this->with_save_filter(
			$filter,
			function () use ( $post, $before ): void {
				$result = $this->replace( $post, '1', 'core/paragraph', self::paragraph( 'UNBAL' ) );

				$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $result ) );
				$this->assertStringContainsString( 'does not stay complete blocks', $result->get_error_message() );
				$this->assertSame( $before, $this->row( $post ) );
			}
		);
	}

	public function test_preserved_refuses_a_kept_block_that_is_missing_from_the_result(): void {
		$stored = self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' );
		$kept   = array(
			array(
				'start'       => 0,
				'end'         => strlen( $stored ),
				'final_start' => 0,
			),
		);

		$this->assertTrue( BlockEditor::preserved( $stored, $kept, $stored ) );
		$this->assertFalse( BlockEditor::preserved( $stored, $kept, self::paragraph( 'One' ) ), 'Block Two is gone.' );
	}

	public function test_preserved_refuses_a_kept_block_that_moved_to_another_offset(): void {
		$stored = self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' );
		$kept   = array(
			array(
				'start'       => 0,
				'end'         => strlen( $stored ),
				'final_start' => 0,
			),
		);

		$this->assertTrue(
			BlockEditor::preserved(
				$stored,
				array(
					array(
						'start'       => 0,
						'end'         => strlen( $stored ),
						'final_start' => 3,
					),
				),
				'abc' . $stored
			)
		);
		$this->assertFalse( BlockEditor::preserved( $stored, $kept, 'abc' . $stored ), 'The kept bytes are at offset 3, not 0.' );
	}

	public function test_preserved_refuses_a_result_that_is_not_complete_blocks(): void {
		$stored = self::paragraph( 'One' );
		$kept   = array(
			array(
				'start'       => 0,
				'end'         => strlen( $stored ),
				'final_start' => 0,
			),
		);

		$this->assertFalse( BlockEditor::preserved( $stored, $kept, $stored . '<!-- /wp:group -->' ) );
	}

	public function test_a_script_left_open_before_the_edited_block_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( "<script>var x = 1;\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$result = $this->replace( $post, '1', 'core/paragraph', self::paragraph( 'Changed' ) );

		$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $result ) );
		$this->assertStringContainsString( 'leaves HTML open', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_textarea_left_open_before_the_edited_block_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( "<textarea>abc\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $this->replace( $post, '1', 'core/paragraph', self::paragraph( 'Changed' ) ) ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_an_attribute_value_left_open_before_the_edited_block_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( "<p title=\"x\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $this->replace( $post, '1', 'core/paragraph', self::paragraph( 'Changed' ) ) ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_closed_script_in_a_custom_html_block_before_the_edited_block_is_not_refused(): void {
		$post = $this->raw_post( '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->' . "\n\n" . self::paragraph( 'Two' ) );

		$out = $this->output_of(
			self::TOOL_NAME,
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

		$this->assertTrue( $out['changed'] );
		$this->assertStringStartsWith( '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>', get_post( $post )->post_content );
	}

	public function test_a_result_that_differs_from_the_checked_preview_is_refused_and_nothing_is_written(): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );
		$flag   = false;
		$filter = static function ( string $content ) use ( &$flag ): string {
			return $flag && str_contains( $content, 'Changed' ) ? $content . '<em>later</em>' : $content;
		};
		// wp_revisions_to_keep() runs inside the snapshot, after the preview was checked and before the write.
		add_filter(
			'wp_revisions_to_keep',
			static function ( $keep ) use ( &$flag ) {
				$flag = true;

				return $keep;
			}
		);

		$this->with_save_filter(
			$filter,
			function () use ( $post, $before ): void {
				$result = $this->replace( $post, '1', 'core/paragraph', self::paragraph( 'Changed' ) );

				$this->assertSame( 'steadhand_update_failed', $this->refusal_code( $result ) );
				$this->assertSame( $before, $this->row( $post ) );
			}
		);
		remove_all_filters( 'wp_revisions_to_keep' );
	}

	public function test_removing_the_only_block_of_a_post_with_no_title_or_excerpt_is_refused_before_the_write(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => self::paragraph( 'Only' ),
			)
		);
		$this->act_as_agent();
		$before = $this->row( $post );

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'   => 'remove',
						'path' => '0',
						'name' => 'core/paragraph',
					),
				),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_sent_script_is_removed_even_when_the_kses_filters_are_not_in_place(): void {
		$post = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		kses_remove_filters();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'Hi<script>alert(1)</script>' ),
					),
				),
			)
		);

		$this->assertStringNotContainsString( '<script', get_post( $post )->post_content );
		$this->assertTrue( $out['content_modified_on_save'] );
	}

	public function test_an_update_post_save_keeps_the_kses_filters_in_place_for_the_whole_save(): void {
		$post = $this->two_post();
		kses_remove_filters();

		$this->in_write(
			static fn() => PostWriter::update( $post, array( 'post_content' => '<p>x</p><script>alert(1)</script>' ) )
		);

		$this->assertStringNotContainsString( '<script', get_post( $post )->post_content, 'The update save must filter the content it writes.' );
	}

	public function test_a_custom_css_strip_is_put_in_place_for_a_sent_block_when_core_has_removed_it(): void {
		$post = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		wp_custom_css_remove_filters();

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => '<!-- wp:paragraph {"style":{"css":"color:red"}} -->' . "\n<p>Css</p>\n" . '<!-- /wp:paragraph -->',
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'color:red', get_post( $post )->post_content );
	}

	/**
	 * A draft with two paragraphs, stored raw.
	 *
	 * @return int
	 */
	private function two_post(): int {
		return $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
	}

	public function test_two_edits_through_edit_blocks_are_both_undoable_and_each_revision_is_the_row_byte_for_byte(): void {
		$admin = '<!-- wp:image -->' . "\n" . '<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="Salt &amp; pepper"/></figure>' . "\n" . '<!-- /wp:image -->'
			. "\n\n" . '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->';
		$post  = $this->raw_post( $admin . "\n\n" . self::paragraph( 'Two' ) );
		$agent = get_current_user_id();

		$rows = array();
		foreach ( array( 'Changed', 'Changed again' ) as $text ) {
			$stored = (string) get_post( $post )->post_content;
			$this->output_of(
				self::TOOL_NAME,
				array(
					'id'         => $post,
					'operations' => array(
						array(
							'op'     => 'replace',
							'path'   => '2',
							'name'   => 'core/paragraph',
							'markup' => self::paragraph( $text ),
						),
					),
				)
			);

			$row      = $this->latest_row( $agent );
			$revision = (int) $row['snapshot_id'];
			$this->assertSame( 1, (int) $row['undoable'], "Edit to $text must be undoable." );
			$this->assertSame( $stored, get_post( $revision )->post_content, 'The revision is the row as it was, byte for byte.' );
			$rows[] = $stored;
		}

		// Every revision of the post, including the ones saved after each edit, keeps the administrator's bytes as they are.
		$revisions = $this->revision_ids( $post );
		// Core does not save a revision equal to the latest one, so the second snapshot adds nothing: three in all.
		$this->assertCount( 3, $revisions );
		foreach ( $revisions as $revision ) {
			$this->assertStringContainsString( '<img src="https://example.com/a.jpg" alt="Salt &amp; pepper"/>', (string) get_post( $revision )->post_content );
			$this->assertStringContainsString( '<script>var x = 1;</script>', (string) get_post( $revision )->post_content );
		}
	}

	public function test_an_update_post_title_edit_on_such_a_post_is_undoable_and_its_revision_is_the_row(): void {
		$admin  = '<!-- wp:image -->' . "\n" . '<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure>' . "\n" . '<!-- /wp:image -->'
			. "\n\n" . '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->';
		$post   = $this->raw_post( $admin );
		$agent  = get_current_user_id();
		$stored = (string) get_post( $post )->post_content;

		$this->output_of(
			'steadhand-update-post',
			array(
				'id'    => $post,
				'title' => 'New title',
			)
		);

		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$this->assertSame( $stored, get_post( (int) $row['snapshot_id'] )->post_content );
		$this->assertSame( $stored, get_post( $post )->post_content, 'An unsent field is kept byte for byte.' );
	}

	public function test_a_revision_of_another_post_saved_during_the_save_is_still_kses_filtered(): void {
		$post     = $this->raw_post( '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->' . "\n\n" . self::paragraph( 'Two' ) );
		$other    = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Other</p>',
			)
		);
		$done     = false;
		$listener = static function ( int $saved ) use ( $post, $other, &$done ): void {
			if ( $saved !== $post || $done ) {
				return;
			}
			$done = true;
			wp_update_post(
				array(
					'ID'           => $other,
					'post_content' => '<p>Other</p><script>alert(1)</script>',
				)
			);
		};
		add_action( 'save_post', $listener );

		try {
			$this->output_of(
				self::TOOL_NAME,
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
		} finally {
			remove_action( 'save_post', $listener );
		}

		$this->assertTrue( $done );
		foreach ( wp_get_post_revisions( $other, array( 'fields' => 'ids' ) ) as $revision ) {
			$this->assertStringNotContainsString( '<script', (string) get_post( $revision )->post_content );
		}
		$this->assertStringNotContainsString( '<script', (string) get_post( $other )->post_content );
	}

	public function test_an_autosave_of_the_post_written_during_the_save_is_not_made_to_mirror_the_row(): void {
		$admin    = '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->';
		$post     = $this->raw_post( $admin . "\n\n" . self::paragraph( 'Two' ) );
		$done     = false;
		$listener = static function ( int $saved ) use ( $post, &$done ): void {
			if ( $saved !== $post || $done ) {
				return;
			}
			$done = true;
			wp_insert_post(
				array(
					'post_type'    => 'revision',
					'post_status'  => 'inherit',
					'post_parent'  => $post,
					'post_name'    => $post . '-autosave-v1',
					'post_title'   => 'Autosave',
					'post_content' => '<p>Autosaved draft</p>',
				)
			);
		};
		add_action( 'save_post', $listener );

		try {
			$this->output_of(
				self::TOOL_NAME,
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
		} finally {
			remove_action( 'save_post', $listener );
		}

		$this->assertTrue( $done );
		$autosave = get_posts(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post,
				'post_status' => 'inherit',
				'name'        => $post . '-autosave-v1',
				'numberposts' => 1,
			)
		);
		$this->assertNotEmpty( $autosave );
		$this->assertSame( '<p>Autosaved draft</p>', (string) $autosave[0]->post_content, 'An autosave keeps the content it was given.' );
	}
}
