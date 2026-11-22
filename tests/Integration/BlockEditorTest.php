<?php
/**
 * The pure block editor behind steadhand/edit-blocks: paths and spans against get-blocks and core, every operation, the
 * separator rule, and every refusal the splice makes.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\BlockEditor;
use Steadhand\Abilities\PostReader;

/**
 * Pure tests: no post is written. The paths are compared with the ones get-blocks returns, and the splices with exact
 * strings.
 */
final class BlockEditorTest extends TestCase {

	/**
	 * A paragraph block as the editor writes it.
	 *
	 * @param string $text Paragraph text.
	 */
	private static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Path => name for a span tree.
	 *
	 * @param array<int, array<string, mixed>> $tree Spans.
	 * @return array<string, string|null>
	 */
	private static function flat( array $tree ): array {
		$out = array();
		foreach ( $tree as $node ) {
			$out[ (string) $node['path'] ] = $node['name'];
			$out                           = $out + self::flat( $node['children'] );
		}

		return $out;
	}

	/**
	 * Path => name for the blocks get-blocks returns for the same content.
	 *
	 * @param string $content Content.
	 * @return array<string, string|null>
	 */
	private static function flat_get_blocks( string $content ): array {
		$walk = static function ( array $blocks ) use ( &$walk ): array {
			$out = array();
			foreach ( $blocks as $block ) {
				$out[ $block['path'] ] = $block['name'];
				$out                   = $out + $walk( $block['inner_blocks'] );
			}

			return $out;
		};

		return $walk( PostReader::blocks( parse_blocks( $content ) )['blocks'] );
	}

	/**
	 * Asserts that a call returned a refusal with the given code, and returns it.
	 *
	 * @param mixed  $result Result of apply().
	 * @param string $code  Expected code.
	 */
	private function refusal( mixed $result, string $code ): \WP_Error {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );

		return $result;
	}

	public function test_paths_and_names_match_get_blocks_for_nested_void_freeform_and_whitespace_gaps(): void {
		$content = "intro text\n\n"
			. self::paragraph( 'one' ) . "\n\n"
			. '<!-- wp:spacer {"height":"10px"} /-->' . "\n \n"
			. "<!-- wp:group -->\n<!-- wp:group -->\n" . self::paragraph( 'deep' ) . "\n<!-- /wp:group -->\n"
			. self::paragraph( 'sibling' ) . "\n<!-- /wp:group -->\n\noutro";

		$expected = array(
			'0'     => null,
			'1'     => 'core/paragraph',
			'2'     => 'core/spacer',
			'3'     => 'core/group',
			'3.0'   => 'core/group',
			'3.0.0' => 'core/paragraph',
			'3.1'   => 'core/paragraph',
			'4'     => null,
		);

		$spans = BlockEditor::spans( $content );
		$this->assertNotNull( $spans );
		$this->assertSame( $expected, self::flat( $spans ) );
		$this->assertSame( $expected, self::flat_get_blocks( $content ) );
	}

	public function test_a_void_block_span_is_its_delimiter_and_a_block_span_runs_to_its_closer(): void {
		$void    = '<!-- wp:spacer {"height":"10px"} /-->';
		$content = self::paragraph( 'a' ) . "\n\n" . $void;

		$spans = BlockEditor::spans( $content );

		$this->assertSame( self::paragraph( 'a' ), substr( $content, $spans[0]['start'], $spans[0]['end'] - $spans[0]['start'] ) );
		$this->assertSame( $void, substr( $content, $spans[1]['start'], $spans[1]['end'] - $spans[1]['start'] ) );
	}

	public function test_an_arrow_inside_a_json_attribute_does_not_end_the_block(): void {
		$content = '<!-- wp:paragraph {"x":"-->"} -->' . "\n<p>a</p>\n<!-- /wp:paragraph -->";

		$spans = BlockEditor::spans( $content );

		$this->assertSame( array( '0' => 'core/paragraph' ), self::flat( $spans ) );
		$this->assertSame( $content, substr( $content, $spans[0]['start'], $spans[0]['end'] - $spans[0]['start'] ) );
		$this->assertSame( self::flat_get_blocks( $content ), self::flat( $spans ) );
	}

	public function test_offsets_are_bytes_so_multibyte_text_before_a_block_is_counted_in_bytes(): void {
		$intro   = "H\u{e9}llo w\u{f6}rld \u{1F642}\n\n";
		$content = $intro . self::paragraph( "\u{fc}" );

		$spans = BlockEditor::spans( $content );

		$this->assertSame( strlen( $intro ), $spans[1]['start'] );
		$this->assertSame( self::paragraph( "\u{fc}" ), substr( $content, $spans[1]['start'] ) );
		$this->assertSame( strlen( $content ), $spans[1]['end'] );
	}

	public function test_a_block_nested_in_a_block_of_the_same_name_is_its_own_path(): void {
		$content = "<!-- wp:group -->\n<!-- wp:group -->\n<!-- wp:group -->\n<!-- /wp:group -->\n<!-- /wp:group -->\n<!-- /wp:group -->";

		$spans = BlockEditor::spans( $content );

		$this->assertSame(
			array(
				'0'     => 'core/group',
				'0.0'   => 'core/group',
				'0.0.0' => 'core/group',
			),
			self::flat( $spans )
		);
		$this->assertSame( $content, substr( $content, $spans[0]['start'], $spans[0]['end'] - $spans[0]['start'] ) );
	}

	public function test_a_freeform_node_that_is_not_whitespace_is_counted_but_a_whitespace_gap_is_not(): void {
		// A form feed is not trimmed, so the text between the two blocks is a freeform node, as get-blocks shows it.
		$content = '<!-- wp:x /-->' . "\f" . '<!-- wp:y /-->';

		$this->assertSame(
			array(
				'0' => 'core/x',
				'1' => null,
				'2' => 'core/y',
			),
			self::flat( BlockEditor::spans( $content ) )
		);
		$this->assertSame( self::flat_get_blocks( $content ), self::flat( BlockEditor::spans( $content ) ) );
	}

	public function test_replace_swaps_the_span_and_leaves_the_gaps_alone(): void {
		$content = $this->three_voids();

		$out = BlockEditor::apply( $content, array( $this->op( 'replace', '1', 'core/b', '<!-- wp:x /-->' ) ) );

		$this->assertSame( "<!-- wp:a /-->\n\n<!-- wp:x /-->\n\n<!-- wp:c /-->", $out );
	}

	public function test_remove_deletes_the_span_and_keeps_the_gaps_on_each_side(): void {
		$out = BlockEditor::apply( $this->three_voids(), array( $this->op( 'remove', '1', 'core/b' ) ) );

		$this->assertSame( "<!-- wp:a /-->\n\n\n\n<!-- wp:c /-->", $out );
	}

	public function test_insert_before_adds_the_markup_and_a_separator_on_the_non_space_side(): void {
		$out = BlockEditor::apply( $this->three_voids(), array( $this->op( 'insert_before', '1', 'core/b', '<!-- wp:x /-->' ) ) );

		$this->assertSame( "<!-- wp:a /-->\n\n<!-- wp:x /-->\n\n<!-- wp:b /-->\n\n<!-- wp:c /-->", $out );
	}

	public function test_insert_after_adds_the_markup_after_the_span(): void {
		$out = BlockEditor::apply( $this->three_voids(), array( $this->op( 'insert_after', '1', 'core/b', '<!-- wp:x /-->' ) ) );

		$this->assertSame( "<!-- wp:a /-->\n\n<!-- wp:b /-->\n\n<!-- wp:x /-->\n\n<!-- wp:c /-->", $out );
	}

	public function test_append_adds_the_markup_at_the_end_with_a_separator_only_when_the_end_is_not_whitespace(): void {
		$this->assertSame(
			"<!-- wp:a /-->\n\n<!-- wp:b /-->\n\n<!-- wp:c /-->\n\n<!-- wp:z /-->",
			BlockEditor::apply(
				$this->three_voids(),
				array(
					array(
						'op'     => 'append',
						'markup' => '<!-- wp:z /-->',
					),
				)
			)
		);
		$this->assertSame(
			"<!-- wp:a /-->\n\n<!-- wp:b /-->\n\n<!-- wp:c /-->\n<!-- wp:z /-->",
			BlockEditor::apply(
				$this->three_voids() . "\n",
				array(
					array(
						'op'     => 'append',
						'markup' => '<!-- wp:z /-->',
					),
				)
			)
		);
		$this->assertSame(
			'<!-- wp:z /-->',
			BlockEditor::apply(
				'',
				array(
					array(
						'op'     => 'append',
						'markup' => '<!-- wp:z /-->',
					),
				)
			)
		);
	}

	public function test_inserts_that_meet_with_no_gap_each_get_one_separator(): void {
		$content = '<!-- wp:a /--><!-- wp:b /-->';

		$out = BlockEditor::apply(
			$content,
			array(
				$this->op( 'insert_after', '0', 'core/a', '<!-- wp:x /-->' ),
				$this->op( 'insert_before', '1', 'core/b', '<!-- wp:y /-->' ),
			)
		);

		$this->assertSame( "<!-- wp:a /-->\n\n<!-- wp:x /-->\n\n<!-- wp:y /-->\n\n<!-- wp:b /-->", $out );
	}

	public function test_an_insert_into_a_gap_with_whitespace_on_both_sides_adds_no_separator(): void {
		$out = BlockEditor::apply( "<!-- wp:a /-->\n\n<!-- wp:b /-->", array( $this->op( 'insert_before', '1', 'core/b', "\n<!-- wp:x /-->\n" ) ) );

		$this->assertSame( "<!-- wp:a /-->\n\n\n<!-- wp:x /-->\n<!-- wp:b /-->", $out );
	}

	public function test_every_path_refers_to_the_stored_content_so_the_order_of_the_operations_does_not_matter(): void {
		$content = $this->three_voids();
		$ops     = array(
			$this->op( 'remove', '0', 'core/a' ),
			$this->op( 'replace', '2', 'core/c', '<!-- wp:z /-->' ),
			$this->op( 'insert_before', '1', 'core/b', '<!-- wp:y /-->' ),
		);

		$forward  = BlockEditor::apply( $content, $ops );
		$backward = BlockEditor::apply( $content, array_reverse( $ops ) );

		$this->assertSame( "\n\n<!-- wp:y /-->\n\n<!-- wp:b /-->\n\n<!-- wp:z /-->", $forward );
		$this->assertSame( $forward, $backward );
	}

	public function test_an_append_follows_the_other_operations_at_the_end_in_the_order_given(): void {
		$out = BlockEditor::apply(
			$this->three_voids(),
			array(
				array(
					'op'     => 'append',
					'markup' => '<!-- wp:e1 /-->',
				),
				$this->op( 'remove', '2', 'core/c' ),
				array(
					'op'     => 'append',
					'markup' => '<!-- wp:e2 /-->',
				),
			)
		);

		$this->assertSame( "<!-- wp:a /-->\n\n<!-- wp:b /-->\n\n<!-- wp:e1 /-->\n\n<!-- wp:e2 /-->", $out );
	}

	public function test_bytes_outside_the_replaced_span_are_byte_identical(): void {
		$before  = "<!-- wp:a  /-->\r\n\t";
		$block   = '<!-- wp:b {"u":"é"} -->' . "\r\n<p>é</p>\r\n<!-- /wp:b -->";
		$after   = "  \r\n";
		$content = $before . $block . $after;

		$out = BlockEditor::apply( $content, array( $this->op( 'replace', '1', 'core/b', '<!-- wp:x /-->' ) ) );

		$this->assertSame( $before . '<!-- wp:x /-->' . $after, $out );
	}

	public function test_a_freeform_root_node_is_its_whole_gap_between_blocks(): void {
		$content = "intro\n\n<!-- wp:a /-->\n\nmiddle\n\n<!-- wp:b /-->";

		// The span of freeform node 0 is "intro\n\n", the text before block 1. Replacing it leaves no gap, as the
		// replacement is not an insert and takes no separator.
		$this->assertSame(
			"<!-- wp:x /--><!-- wp:a /-->\n\nmiddle\n\n<!-- wp:b /-->",
			BlockEditor::apply( $content, array( $this->freeform( 'replace', '0', '<!-- wp:x /-->' ) ) )
		);
		// The span of freeform node 2 is "\n\nmiddle\n\n", between blocks 1 and 3.
		$this->assertSame(
			"intro\n\n<!-- wp:a /--><!-- wp:b /-->",
			BlockEditor::apply( $content, array( $this->freeform( 'remove', '2' ) ) )
		);
		$this->assertSame(
			"intro\n\n<!-- wp:a /-->\n\n<!-- wp:x /-->\n\nmiddle\n\n<!-- wp:b /-->",
			BlockEditor::apply( $content, array( $this->freeform( 'insert_before', '2', '<!-- wp:x /-->' ) ) )
		);
	}

	public function test_content_with_no_blocks_is_one_freeform_node(): void {
		$content = 'Just <b>text</b>.';

		$this->assertSame( array( '0' => null ), self::flat( BlockEditor::spans( $content ) ) );
		$this->assertSame( "$content\n\n<!-- wp:x /-->", BlockEditor::apply( $content, array( $this->op( 'append', null, null, '<!-- wp:x /-->' ) ) ) );
	}

	public function test_unbalanced_markup_is_unsupported_and_refused_as_such(): void {
		$cases = array(
			'unclosed'       => "<!-- wp:a /-->\n<!-- wp:b -->\n<p>x</p>",
			'closer alone'   => "<p>x</p>\n<!-- /wp:a -->",
			'mismatched'     => '<!-- wp:a --><!-- /wp:b -->',
			'out of order'   => '<!-- wp:a --><!-- wp:b --><!-- /wp:a --><!-- /wp:b -->',
			'bad attributes' => '<!-- wp:x {bad} /-->',
			'not utf-8'      => "\xC3\x28<!-- wp:a /-->",
		);

		foreach ( $cases as $case => $content ) {
			$this->assertNull( BlockEditor::spans( $content ), $case );
			$error = $this->refusal( BlockEditor::apply( $content, array( $this->op( 'remove', '0', null ) ) ), 'steadhand_refused_unsupported_markup' );
			$this->assertSame( 409, $error->get_error_data()['status'], $case );
			$this->assertStringContainsString( 'update-post', $error->get_error_message(), $case );
		}
	}

	/**
	 * Removes the parser filter the disagreement test adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'block_parser_class' );
		parent::tearDown();
	}

	public function test_a_closer_that_names_another_block_is_unsupported_even_though_core_would_build_a_tree(): void {
		// Core builds a tree for this, because it reads closers without checking their names. The scan must not.
		$content = '<!-- wp:a --><!-- wp:b --><!-- /wp:a --><!-- /wp:b -->';

		$this->assertNull( BlockEditor::spans( $content ) );
		$this->refusal( BlockEditor::apply( $content, array( $this->op( 'remove', '0', 'core/a' ) ) ), 'steadhand_refused_unsupported_markup' );
	}

	public function test_a_disagreement_between_the_scan_and_core_is_unsupported(): void {
		$content = self::paragraph( 'one' ) . "\n\n" . self::paragraph( 'two' );
		$this->assertNotNull( BlockEditor::spans( $content ), 'Without a disagreement the same content is supported.' );

		add_filter(
			'block_parser_class',
			static fn(): string => RenamingBlockParser::class
		);

		$this->assertNull( BlockEditor::spans( $content ), 'The scan must not be trusted when core reads the blocks differently.' );
		$this->refusal( BlockEditor::apply( $content, array( $this->op( 'replace', '1', 'core/paragraph', '<!-- wp:x /-->' ) ) ), 'steadhand_refused_unsupported_markup' );
	}

	public function test_a_missing_path_or_a_name_that_differs_is_a_block_mismatch(): void {
		$content = $this->three_voids();
		$cases   = array(
			'missing path'      => $this->op( 'replace', '5', 'core/a', '<!-- wp:x /-->' ),
			'deeper than there' => $this->op( 'replace', '1.0', 'core/b', '<!-- wp:x /-->' ),
			'wrong name'        => $this->op( 'replace', '1', 'core/heading', '<!-- wp:x /-->' ),
			'no namespace'      => $this->op( 'replace', '1', 'b', '<!-- wp:x /-->' ),
			'null for a block'  => $this->op( 'replace', '1', null, '<!-- wp:x /-->' ),
		);

		foreach ( $cases as $case => $operation ) {
			$error = $this->refusal( BlockEditor::apply( $content, array( $operation ) ), 'steadhand_refused_block_mismatch' );
			$this->assertSame( 409, $error->get_error_data()['status'], $case );
			$this->assertStringContainsString( 'get-blocks', $error->get_error_message(), $case );
			$this->assertStringNotContainsString( 'core/b', $error->get_error_message(), 'The message must not echo the post.' );
		}
	}

	public function test_a_freeform_node_is_matched_by_a_null_name_and_a_block_name_is_a_mismatch_there(): void {
		$content = "intro\n\n<!-- wp:a /-->";

		$this->assertSame( '<!-- wp:a /-->', BlockEditor::apply( $content, array( $this->op( 'remove', '0', null ) ) ) );
		$this->refusal( BlockEditor::apply( $content, array( $this->op( 'replace', '0', 'core/a', '<!-- wp:x /-->' ) ) ), 'steadhand_refused_block_mismatch' );
	}

	public function test_the_checks_refuse_each_malformed_operation_with_a_message_that_says_what_is_allowed(): void {
		$markup = '<!-- wp:x /-->';
		$cases  = array(
			'no operations'            => array(),
			'too many'                 => array_map(
				static fn( int $n ): array => array(
					'op'   => 'remove',
					'path' => (string) $n,
					'name' => null,
				),
				range( 0, BlockEditor::MAX_OPERATIONS )
			),
			'not an object'            => array( 'remove' ),
			'no op'                    => array(
				array(
					'path'   => '0',
					'name'   => null,
					'markup' => $markup,
				),
			),
			'unknown op'               => array(
				array(
					'op'     => 'move',
					'path'   => '0',
					'name'   => null,
					'markup' => $markup,
				),
			),
			'append with a path'       => array(
				array(
					'op'     => 'append',
					'path'   => '0',
					'markup' => $markup,
				),
			),
			'append with a name'       => array(
				array(
					'op'     => 'append',
					'name'   => null,
					'markup' => $markup,
				),
			),
			'replace without a name'   => array(
				array(
					'op'     => 'replace',
					'path'   => '0',
					'markup' => $markup,
				),
			),
			'name that is a number'    => array( $this->op( 'replace', '0', 5, $markup ) ),
			'blank markup'             => array( $this->op( 'replace', '0', null, " \n\t" ) ),
			'no markup'                => array(
				array(
					'op'   => 'insert_after',
					'path' => '0',
					'name' => null,
				),
			),
			'markup on remove'         => array( $this->op( 'remove', '0', null, $markup ) ),
			'markup that is not utf-8' => array( $this->op( 'insert_after', '0', null, "\xC3\x28" ) ),
			'path with a newline'      => array( $this->op( 'remove', "0\n", null ) ),
			'path with a leading zero' => array( $this->op( 'remove', '01', null ) ),
			'path ending in a dot'     => array( $this->op( 'remove', '1.', null ) ),
			'path that is a word'      => array( $this->op( 'remove', 'a', null ) ),
			'path of 21 levels'        => array( $this->op( 'remove', str_repeat( '0.', 20 ) . '0', null ) ),
			'name too long'            => array( $this->op( 'remove', '0', str_repeat( 'a', 201 ) ) ),
		);

		foreach ( $cases as $case => $operations ) {
			$error = BlockEditor::check_operations( $operations );
			$this->assertWPError( $error, $case );
			$this->assertSame( 'steadhand_invalid_input', $error->get_error_code(), $case );
			$this->assertSame( 400, $error->get_error_data()['status'], $case );
			$this->assertNotSame( '', $error->get_error_message(), $case );
		}
	}

	public function test_well_formed_operations_pass_the_checks(): void {
		$markup = '<!-- wp:x /-->';

		$this->assertNull( BlockEditor::check_operations( array( $this->op( 'remove', '0', null ) ) ) );
		$this->assertNull(
			BlockEditor::check_operations(
				array(
					array(
						'op'     => 'append',
						'markup' => $markup,
					),
				)
			)
		);
		$this->assertNull( BlockEditor::check_operations( array( $this->op( 'replace', '1', 'core/b', $markup ), $this->op( 'remove', '10', 'core/c' ) ) ) );
		$this->assertNull( BlockEditor::check_operations( array( $this->op( 'replace', str_repeat( '0.', 19 ) . '0', null, $markup ) ) ) );
		$this->assertNull(
			BlockEditor::check_operations(
				array(
					$this->op( 'insert_before', '0', null, $markup ),
					array(
						'op'     => 'append',
						'markup' => $markup,
					),
				)
			)
		);
	}

	public function test_paths_that_name_the_same_block_or_lie_inside_one_are_refused(): void {
		$markup = '<!-- wp:x /-->';
		$cases  = array(
			'same path twice'              => array( $this->op( 'replace', '1', 'core/b', $markup ), $this->op( 'remove', '1', 'core/b' ) ),
			'insert before and after'      => array( $this->op( 'insert_before', '1', 'core/b', $markup ), $this->op( 'insert_after', '1', 'core/b', $markup ) ),
			'a path inside another'        => array( $this->op( 'replace', '0', 'core/a', $markup ), $this->op( 'remove', '0.1', null ) ),
			'the other way round'          => array( $this->op( 'remove', '0.1', null ), $this->op( 'replace', '0', 'core/a', $markup ) ),
			'a deeper path inside another' => array( $this->op( 'remove', '2', 'core/c' ), $this->op( 'remove', '2.0.0', null ), $this->op( 'remove', '1', 'core/b' ) ),
		);

		foreach ( $cases as $case => $operations ) {
			$this->assertSame( 'steadhand_invalid_input', BlockEditor::check_operations( $operations )->get_error_code(), $case );
			$this->assertSame( 'steadhand_invalid_input', $this->refusal( BlockEditor::apply( $this->three_voids(), $operations ), 'steadhand_invalid_input' )->get_error_code(), $case );
		}

		$this->assertNull(
			BlockEditor::check_operations(
				array(
					$this->op( 'replace', '1', 'core/b', $markup ),
					$this->op( 'remove', '10', 'core/c' ),
					$this->op( 'replace', '0', 'core/a', $markup ),
				)
			),
			'A path that only shares a prefix is a different block.'
		);
	}

	/**
	 * Three void blocks in a row, separated by a blank line, named core/a, core/b and core/c.
	 */
	private function three_voids(): string {
		return "<!-- wp:a /-->\n\n<!-- wp:b /-->\n\n<!-- wp:c /-->";
	}

	/**
	 * An operation for a block, as the tool takes it. The keys are left out when their value is null for append and remove.
	 *
	 * @param string      $op     Operation.
	 * @param string|null $path   Path, or null.
	 * @param mixed       $name   Block name, or null, or another value for a refusal test.
	 * @param string|null $markup Markup, or null.
	 * @return array<string, mixed>
	 */
	private function op( string $op, ?string $path, mixed $name = null, ?string $markup = null ): array {
		$operation = array( 'op' => $op );
		if ( null !== $path ) {
			$operation['path'] = $path;
		}
		if ( 'append' !== $op ) {
			$operation['name'] = $name;
		}
		if ( null !== $markup ) {
			$operation['markup'] = $markup;
		}

		return $operation;
	}

	/**
	 * A freeform (root text) operation.
	 *
	 * @param string      $op     Operation.
	 * @param string      $path   Path of the freeform node.
	 * @param string|null $markup Markup.
	 */
	private function freeform( string $op, string $path, ?string $markup = null ): array {
		return $this->op( $op, $path, null, $markup );
	}
}
