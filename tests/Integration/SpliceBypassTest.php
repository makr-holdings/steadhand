<?php
/**
 * The split-delimiter bypass of steadhand/edit-blocks: a block delimiter completed by bytes outside the sent run. Covers the
 * token-alignment check (called directly and through the pipeline), the stray-comment rule at both places it applies, the
 * positive comment cases, and the checks that the other layers keep.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\BlockEditor;
use Steadhand\Pipeline\PostWriter;

/**
 * Tests the split-delimiter bypass checks of steadhand/edit-blocks.
 */
final class SpliceBypassTest extends PostWriteTestCase {

	private const ABILITY = 'steadhand/edit-blocks';

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
	 * The group of the proof of concept: two paragraphs, with a classic HTML line between them.
	 *
	 * @return string
	 */
	private static function group(): string {
		return "<!-- wp:group -->\n<div class=\"g\">" . self::paragraph( 'a' ) . "\n<hr>\n" . self::paragraph( 'b' ) . "</div>\n<!-- /wp:group -->";
	}

	/**
	 * Stores raw content on a post the agent will edit. Returns the post.
	 *
	 * @param string $content Argument.
	 * @return int
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
	 * The operations of one call, each an operation array, as the tool takes them.
	 *
	 * @param int   $post Argument.
	 * @param array $operations Argument.
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
	 * A replace of a block at a path, with the name of a paragraph.
	 *
	 * @param string $path Argument.
	 * @param string $markup Argument.
	 * @return array
	 */
	private function replace( string $path, string $markup ): array {
		return array(
			'op'     => 'replace',
			'path'   => $path,
			'name'   => 'core/paragraph',
			'markup' => $markup,
		);
	}

	/**
	 * The kept ranges, and the sent runs, that the proof of concept makes, and the final content they produce.
	 *
	 * @return array
	 */
	private function poc_layout(): array {
		$stored = self::group();
		$pa     = self::paragraph( 'a' );
		$pb     = self::paragraph( 'b' );
		$a0     = (int) strpos( $stored, $pa );
		$a1     = $a0 + strlen( $pa );
		$b0     = (int) strpos( $stored, $pb );
		$b1     = $b0 + strlen( $pb );
		$r1     = '<!-- wp:template-part {"q":"-->';
		$r2     = '<!-- ","slug":"header","tagName":"script"} /-->';

		$k1_final = 0;
		$r1_at    = $k1_final + $a0;
		$k2_final = $r1_at + strlen( $r1 );
		$r2_at    = $k2_final + ( $b0 - $a1 );
		$k3_final = $r2_at + strlen( $r2 );

		$kept  = array(
			array(
				'start'       => 0,
				'end'         => $a0,
				'final_start' => $k1_final,
			),
			array(
				'start'       => $a1,
				'end'         => $b0,
				'final_start' => $k2_final,
			),
			array(
				'start'       => $b1,
				'end'         => strlen( $stored ),
				'final_start' => $k3_final,
			),
		);
		$runs  = array(
			array(
				'at'   => $r1_at,
				'text' => $r1,
			),
			array(
				'at'   => $r2_at,
				'text' => $r2,
			),
		);
		$final = substr( $stored, 0, $a0 ) . $r1 . substr( $stored, $a1, $b0 - $a1 ) . $r2 . substr( $stored, $b1 );

		return array( $stored, $kept, $runs, $final );
	}

	public function test_proof_of_concept_one_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( self::group() );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				$this->replace( '0.0', '<!-- wp:template-part {"q":"-->' ),
				$this->replace( '0.1', '<!-- ","slug":"header","tagName":"script"} /-->' ),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_proof_of_concept_one_with_an_image_url_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( self::group() );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				$this->replace( '0.0', '<!-- wp:template-part {"q":"-->' ),
				$this->replace( '0.1', '<!-- wp:image {"url":"javascript:alert(1)"} /-->' ),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_class_name_attribute_injection_split_across_two_pieces_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( self::group() );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				$this->replace( '0.0', '<!-- wp:template-part {"className":"x" --' ),
				$this->replace( '0.1', '<!-- onload="alert(1)" /-->' ),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_stored_first_half_completed_by_a_sent_second_half_is_refused_and_the_row_is_unchanged(): void {
		$stored = '<!-- wp:group -->' . "\n" . '<div class="g">' . self::paragraph( 'Hi <!-- wp:template-part {"q":"-->' ) . "\n" . self::paragraph( 'b' ) . '</div>' . "\n" . '<!-- /wp:group -->';
		$post   = $this->raw_post( $stored );
		$before = $this->row( $post );

		$result = $this->call( $post, array( $this->replace( '0.1', '","slug":"header","tagName":"script"} /-->' ) ) );

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_first_half_stored_at_the_root_and_a_sent_second_half_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( '<!-- wp:template-part {"q":"-->' . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'     => 'replace',
					'path'   => '1',
					'name'   => 'core/paragraph',
					'markup' => '","tagName":"script"} /-->',
				),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_halves_sent_in_insert_before_insert_after_and_append_are_each_refused_and_the_row_is_unchanged(): void {
		$cases = array(
			'insert_before' => array(
				'op'     => 'insert_before',
				'path'   => '1',
				'name'   => 'core/paragraph',
				'markup' => '<!-- wp:template-part {"q":"-->',
			),
			'insert_after'  => array(
				'op'     => 'insert_after',
				'path'   => '0',
				'name'   => 'core/paragraph',
				'markup' => '<!-- ","tagName":"script"} /-->',
			),
			'append'        => array(
				'op'     => 'append',
				'markup' => '<!-- ","tagName":"script"} /-->',
			),
		);

		foreach ( $cases as $case => $operation ) {
			$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
			$before = $this->row( $post );

			$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $this->call( $post, array( $operation ) ) ), $case );
			$this->assertSame( $before, $this->row( $post ), $case );
		}
	}

	public function test_the_alignment_check_refuses_the_proof_of_concept_pieces(): void {
		list( $stored, $kept, $runs, $final ) = $this->poc_layout();

		$this->assertFalse( BlockEditor::aligned( $stored, $kept, $runs, $final ) );
		$this->assertStringContainsString( 'slug":"header"', $final, 'The final content holds the split delimiter.' );
	}

	public function test_the_alignment_check_accepts_the_same_layout_with_complete_pieces(): void {
		$stored = self::group();
		$pa     = self::paragraph( 'a' );
		$a0     = (int) strpos( $stored, $pa );
		$a1     = $a0 + strlen( $pa );
		$new    = self::paragraph( 'Changed' );
		$final  = substr( $stored, 0, $a0 ) . $new . substr( $stored, $a1 );

		$this->assertTrue(
			BlockEditor::aligned(
				$stored,
				array(
					array(
						'start'       => 0,
						'end'         => $a0,
						'final_start' => 0,
					),
					array(
						'start'       => $a1,
						'end'         => strlen( $stored ),
						'final_start' => $a0 + strlen( $new ),
					),
				),
				array(
					array(
						'at'   => $a0,
						'text' => $new,
					),
				),
				$final
			)
		);
	}

	public function test_condition_one_alone_refuses_a_delimiter_that_crosses_a_kept_range_and_a_sent_run(): void {
		$stored = '<!-- wp:x ';

		$this->assertFalse(
			BlockEditor::aligned(
				$stored,
				array(
					array(
						'start'       => 0,
						'end'         => strlen( $stored ),
						'final_start' => 0,
					),
				),
				array(
					array(
						'at'   => strlen( $stored ),
						'text' => '{"a":1} -->',
					),
				),
				'<!-- wp:x {"a":1} -->'
			),
			'The whole delimiter is in the final content, but it is not inside one region.'
		);
	}

	public function test_condition_two_alone_refuses_a_stored_delimiter_whose_bytes_change_inside_a_kept_range(): void {
		$stored = self::paragraph( 'a' );
		$stored = str_replace( '<!-- wp:paragraph -->', '<!-- wp:paragraph {"x":1} -->', $stored );

		$this->assertFalse(
			BlockEditor::aligned(
				$stored,
				array(
					array(
						'start'       => 0,
						'end'         => strlen( $stored ),
						'final_start' => 0,
					),
				),
				array(),
				str_replace( '"x":1', '"x":2', $stored )
			)
		);
	}

	public function test_condition_two_alone_refuses_a_stored_delimiter_that_is_missing_from_a_kept_range(): void {
		$stored = self::paragraph( 'a' );

		$this->assertFalse(
			BlockEditor::aligned(
				$stored,
				array(
					array(
						'start'       => 0,
						'end'         => strlen( $stored ),
						'final_start' => 0,
					),
				),
				array(),
				str_replace( '<!-- wp:paragraph -->', '', $stored )
			)
		);
	}

	public function test_condition_two_alone_refuses_a_stored_delimiter_cut_by_the_edge_of_a_kept_range(): void {
		$stored = self::paragraph( 'a' ) . "\n\n" . self::paragraph( 'b' );

		$this->assertFalse(
			BlockEditor::aligned(
				$stored,
				array(
					array(
						'start'       => 0,
						'end'         => strlen( self::paragraph( 'a' ) ) + 5,
						'final_start' => 0,
					),
				),
				array(),
				substr( $stored, 0, strlen( self::paragraph( 'a' ) ) + 5 )
			),
			'The second paragraph is cut by the kept range and is not wholly inside it.'
		);
	}

	public function test_a_stray_comment_sent_with_a_complete_block_is_refused_at_the_input_and_nothing_is_written(): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'     => 'insert_before',
					'path'   => '1',
					'name'   => 'core/paragraph',
					'markup' => '<!-- note -->' . "\n" . self::paragraph( 'x' ),
				),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_stray_closer_sent_after_a_complete_block_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'     => 'insert_after',
					'path'   => '0',
					'name'   => 'core/paragraph',
					'markup' => self::paragraph( 'x' ) . ' -->',
				),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_the_sent_run_check_refuses_a_stray_comment_even_when_the_input_check_is_not_there(): void {
		$post = $this->raw_post( self::paragraph( 'One' ) );

		$result = $this->in_write(
			static fn() => PostWriter::assemble_report(
				$post,
				self::paragraph( 'One' ),
				array(
					array(
						'type' => 'sent',
						'text' => '<!-- note -->',
					),
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_refused_unsupported_markup', $result->get_error_code() );
		$this->assertStringContainsString( 'not a block comment', $result->get_error_message() );
	}

	public function test_sent_markup_that_leaves_html_open_before_stored_bytes_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'     => 'insert_before',
					'path'   => '1',
					'name'   => 'core/paragraph',
					'markup' => '<textarea>abc',
				),
			)
		);

		$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $result ) );
		$this->assertStringContainsString( 'leaves HTML open', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_sent_markup_that_closes_what_it_opens_is_not_refused_by_the_open_html_check(): void {
		$post = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );

		$out = $this->output_of(
			'steadhand-edit-blocks',
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'insert_before',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => '<textarea>abc</textarea>',
					),
				),
			)
		);

		$this->assertTrue( $out['changed'] );
		$this->assertStringContainsString( '<textarea>abc</textarea>', (string) get_post( $post )->post_content );
	}

	public function test_break_comments_inside_their_core_blocks_are_accepted_and_stored(): void {
		$forms = array(
			'<!-- wp:more -->' . "\n" . '<!--more-->' . "\n" . '<!-- /wp:more -->' => '<!--more-->',
			'<!-- wp:more {"customText":"Read on"} -->' . "\n" . '<!--more Read on-->' . "\n" . '<!-- /wp:more -->' => '<!--more Read on-->',
			'<!-- wp:more {"noTeaser":true} -->' . "\n" . '<!--more-->' . "\n" . '<!--noteaser-->' . "\n" . '<!-- /wp:more -->' => '<!--noteaser-->',
			'<!-- wp:nextpage -->' . "\n" . '<!--nextpage-->' . "\n" . '<!-- /wp:nextpage -->' => '<!--nextpage-->',
		);

		foreach ( $forms as $markup => $comment ) {
			$post = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );

			$out = $this->output_of(
				'steadhand-edit-blocks',
				array(
					'id'         => $post,
					'operations' => array( $this->replace( '1', self::paragraph( 'Two' ) . "\n" . $markup ) ),
				)
			);

			$this->assertTrue( $out['changed'], $comment );
			$this->assertStringContainsString( $comment, (string) get_post( $post )->post_content, $comment );
		}
	}

	/**
	 * Removing a block whose open textarea would leave the following script inert-looking is refused, and the row is unchanged.
	 */
	public function test_removing_the_block_that_opens_a_textarea_before_a_script_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( "<!-- wp:html -->\n<textarea>\n<!-- /wp:html -->\n\n<!-- wp:html -->\n<script>alert(1)</script>\n<!-- /wp:html -->" );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'   => 'remove',
					'path' => '0',
					'name' => 'core/html',
				),
			)
		);

		$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	/**
	 * Replacing a block whose open textarea would leave the following script inert-looking is refused, and the row is unchanged.
	 */
	public function test_replacing_the_block_that_opens_a_textarea_before_a_script_is_refused_and_the_row_is_unchanged(): void {
		$post   = $this->raw_post( "<!-- wp:html -->\n<textarea>\n<!-- /wp:html -->\n\n<!-- wp:html -->\n<script>alert(1)</script>\n<!-- /wp:html -->" );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'     => 'replace',
					'path'   => '0',
					'name'   => 'core/html',
					'markup' => "<!-- wp:html -->\n<p>New</p>\n<!-- /wp:html -->",
				),
			)
		);

		$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	/**
	 * A break comment that is not the sole inner content of its owner core/more or core/nextpage block is refused.
	 */
	public function test_a_break_comment_that_is_not_the_sole_inner_content_of_its_owner_is_refused_and_the_row_is_unchanged(): void {
		$forms = array(
			'core/more > core/group > comment' => '<!-- wp:more -->' . "\n" . '<!-- wp:group -->' . "\n" . '<div class="wp-block-group"><!--more--></div>' . "\n" . '<!-- /wp:group -->' . "\n" . '<!-- /wp:more -->',
			'nextpage inside more'             => '<!-- wp:more -->' . "\n" . '<!--more-->' . "\n" . '<!-- wp:nextpage -->' . "\n" . '<!--nextpage-->' . "\n" . '<!-- /wp:nextpage -->' . "\n" . '<!-- /wp:more -->',
			'more comment in nextpage'         => '<!-- wp:nextpage -->' . "\n" . '<!--more-->' . "\n" . '<!-- /wp:nextpage -->',
			'comment in more attribute'        => '<!-- wp:more {"x":"<!--more-->"} -->' . "\n" . '<!--more-->' . "\n" . '<!-- /wp:more -->',
		);

		foreach ( $forms as $label => $markup ) {
			$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
			$before = $this->row( $post );

			$result = $this->call( $post, array( $this->replace( '1', self::paragraph( 'Two' ) . "\n" . $markup ) ) );

			$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ), $label );
			$this->assertSame( $before, $this->row( $post ), $label );
		}
	}

	/**
	 * Removing a block between closed paragraphs is not refused by the join check.
	 */
	public function test_removing_a_block_between_closed_paragraphs_is_accepted(): void {
		$post = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) . "\n\n" . self::paragraph( 'Three' ) );

		$out = $this->output_of(
			'steadhand-edit-blocks',
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'   => 'remove',
						'path' => '1',
						'name' => 'core/paragraph',
					),
				),
			)
		);

		$this->assertTrue( $out['changed'] );
		$this->assertStringNotContainsString( 'Two', (string) get_post( $post )->post_content );
	}

	public function test_an_ordinary_html_comment_in_stored_bytes_is_kept_and_the_edit_goes_ahead(): void {
		$post = $this->raw_post( '<!-- note -->' . "\n\n" . self::paragraph( 'Two' ) );

		$out = $this->output_of(
			'steadhand-edit-blocks',
			array(
				'id'         => $post,
				'operations' => array( $this->replace( '1', self::paragraph( 'Changed' ) ) ),
			)
		);

		$this->assertTrue( $out['changed'] );
		$this->assertSame( '<!-- note -->' . "\n\n" . self::paragraph( 'Changed' ), get_post( $post )->post_content );
	}

	public function test_inserting_classic_html_before_a_root_freeform_node_is_refused_by_the_structure_check(): void {
		$post   = $this->raw_post( self::paragraph( 'One' ) . "\n\nintro text\n\n" . self::paragraph( 'Two' ) );
		$before = $this->row( $post );

		$result = $this->call(
			$post,
			array(
				array(
					'op'     => 'insert_before',
					'path'   => '1',
					'name'   => null,
					'markup' => '<p>new</p>',
				),
			)
		);

		$this->assertSame( 'steadhand_refused_unsupported_markup', $this->refusal_code( $result ) );
		$this->assertStringContainsString( 'would change blocks it does not name', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_kept_block_whose_length_changes_is_refused_by_the_structure_check(): void {
		$stored = self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' );
		$final  = str_replace( '<p>Two</p>', '<p>Two!</p>', $stored );

		$this->assertFalse(
			BlockEditor::preserved(
				$stored,
				array(
					array(
						'start'       => 0,
						'end'         => strlen( $stored ),
						'final_start' => 0,
					),
				),
				$final
			),
			'The whole second block is kept, so its length must stay the same.'
		);
	}

	public function test_an_update_of_a_revision_during_a_save_keeps_the_content_it_was_given(): void {
		$post = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$this->output_of(
			'steadhand-edit-blocks',
			array(
				'id'         => $post,
				'operations' => array( $this->replace( '1', self::paragraph( 'Changed' ) ) ),
			)
		);
		$revision = (int) ( $this->revision_ids( $post )[0] ?? 0 );
		$this->assertGreaterThan( 0, $revision );
		$done     = false;
		$listener = static function ( int $saved ) use ( $post, $revision, &$done ): void {
			if ( $saved !== $post || $done ) {
				return;
			}
			$done = true;
			wp_update_post(
				array(
					'ID'           => $revision,
					'post_content' => '<p>edited</p>',
				)
			);
		};
		add_action( 'save_post', $listener );

		try {
			$this->output_of(
				'steadhand-edit-blocks',
				array(
					'id'         => $post,
					'operations' => array( $this->replace( '1', self::paragraph( 'Again' ) ) ),
				)
			);
		} finally {
			remove_action( 'save_post', $listener );
		}

		$this->assertTrue( $done );
		$this->assertSame( '<p>edited</p>', (string) get_post( $revision )->post_content );
	}

	public function test_a_css_value_with_an_escaped_quote_is_stripped(): void {
		$this->skip_without_custom_css_strip();
		$post = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$css  = '<!-- wp:paragraph {"style":{"css":"x\\"y"}} -->' . "\n<p>Css</p>\n" . '<!-- /wp:paragraph -->';

		$this->output_of(
			'steadhand-edit-blocks',
			array(
				'id'         => $post,
				'operations' => array( $this->replace( '1', $css ) ),
			)
		);

		$stored = (string) get_post( $post )->post_content;
		$this->assertStringNotContainsString( 'css', $stored, 'A CSS value with an escaped quote must be stripped.' );
		$this->assertStringNotContainsString( 'u0022', $stored );
	}
	public function test_an_agent_who_may_edit_css_still_has_the_css_stripped_from_its_sent_blocks(): void {
		$this->skip_without_custom_css_strip();
		$post = $this->raw_post( self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ) );
		$this->act_as_agent( array( 'edit_css' => true ) );

		$this->output_of(
			'steadhand-edit-blocks',
			array(
				'id'         => $post,
				'operations' => array( $this->replace( '1', '<!-- wp:paragraph {"style":{"css":"color:red"}} -->' . "\n<p>Css</p>\n" . '<!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertStringNotContainsString( 'color:red', (string) get_post( $post )->post_content );
	}
}
