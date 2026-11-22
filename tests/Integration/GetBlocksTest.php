<?php
/**
 * The steadhand/get-blocks ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\PostReader;

/**
 * Covers SPEC §5 (get-blocks) and the block path contract that later edit tools rely on.
 */
final class GetBlocksTest extends ReadToolTestCase {

	private const TOOL_NAME = 'steadhand-get-blocks';

	/**
	 * Columns with a nested paragraph, freeform HTML between blocks, a block with attributes, and a block with none.
	 */
	private const CONTENT = "<!-- wp:columns --><div class=\"wp-block-columns\"><!-- wp:column --><div class=\"wp-block-column\"><!-- wp:paragraph --><p>Inside</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->\n\n<p>Classic freeform</p>\n\n<!-- wp:image {\"id\":5,\"sizeSlug\":\"large\"} --><figure class=\"wp-block-image size-large\"><img src=\"x.jpg\"/></figure><!-- /wp:image -->\n\n<!-- wp:separator --><hr class=\"wp-block-separator\"/><!-- /wp:separator -->";

	/**
	 * Calls the tool and returns the structured output.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private function blocks_of( int $id ): array {
		return $this->output( self::TOOL_NAME, array( 'id' => $id ) );
	}

	public function test_nested_blocks_freeform_html_and_attributes_come_back_with_index_paths(): void {
		$this->act_as_agent();
		$id = $this->admin_post( array( 'post_content' => self::CONTENT ) );

		$output = $this->blocks_of( $id );
		$top    = $output['blocks'];

		$this->assertSame( $id, $output['id'] );
		$this->assertFalse( $output['truncated'] );
		$this->assertSame( 6, $output['block_count'], 'Columns, column, paragraph, freeform, image and separator. Whitespace between blocks is not counted.' );
		$this->assertSame( array( '0', '1', '2', '3' ), array_column( $top, 'path' ) );
		$this->assertSame( array( 'core/columns', null, 'core/image', 'core/separator' ), array_column( $top, 'name' ) );

		$columns = $top[0];
		$this->assertSame( '0.0', $columns['inner_blocks'][0]['path'] );
		$this->assertSame( 'core/column', $columns['inner_blocks'][0]['name'] );
		$this->assertSame( '0.0.0', $columns['inner_blocks'][0]['inner_blocks'][0]['path'] );
		$this->assertSame( 'core/paragraph', $columns['inner_blocks'][0]['inner_blocks'][0]['name'] );
		$this->assertStringContainsString( '<p>Inside</p>', $columns['inner_blocks'][0]['inner_blocks'][0]['inner_html'] );

		$this->assertStringContainsString( '<p>Classic freeform</p>', $top[1]['inner_html'] );
		$this->assertSame( array(), $top[1]['inner_blocks'], 'Freeform HTML has no inner blocks.' );

		$this->assertSame(
			array(
				'id'       => 5,
				'sizeSlug' => 'large',
			),
			(array) $top[2]['attrs']
		);
	}

	/**
	 * Calls the tool and returns the raw JSON text of the result, so the object-or-array shape is visible on the wire.
	 *
	 * @param int $id Post ID.
	 */
	private function raw_result_text( int $id ): string {
		$response = $this->mcp(
			'tools/call',
			array(
				'name'      => self::TOOL_NAME,
				'arguments' => (object) array( 'id' => $id ),
			),
			$this->mcp_session()
		);

		return (string) $this->body( $response )['result']['content'][0]['text'];
	}

	public function test_an_empty_attribute_set_is_a_json_object_on_the_wire_not_an_array(): void {
		$this->act_as_agent();
		$id = $this->admin_post( array( 'post_content' => self::CONTENT ) );

		$raw = $this->raw_result_text( $id );

		$this->assertStringContainsString( '"attrs":{}', $raw, 'The separator has no attributes.' );
		$this->assertStringNotContainsString( '"attrs":[]', $raw );
	}

	public function test_a_post_with_no_blocks_returns_one_freeform_node(): void {
		$this->act_as_agent();
		$id = $this->admin_post( array( 'post_content' => '<p>Only classic HTML</p>' ) );

		$output = $this->blocks_of( $id );

		$this->assertSame( 1, $output['block_count'] );
		$this->assertNull( $output['blocks'][0]['name'] );
		$this->assertSame( '<p>Only classic HTML</p>', $output['blocks'][0]['inner_html'] );
	}

	public function test_a_post_with_no_content_has_no_blocks(): void {
		$this->act_as_agent();
		$id = $this->admin_post( array( 'post_content' => '' ) );

		$output = $this->blocks_of( $id );

		$this->assertSame( array(), $output['blocks'] );
		$this->assertSame( 0, $output['block_count'] );
		$this->assertFalse( $output['truncated'] );
	}

	public function test_more_than_max_blocks_is_cut_and_reported(): void {
		$this->act_as_agent();
		$content = str_repeat( "<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->\n", PostReader::MAX_BLOCKS + 1 );
		$id      = $this->admin_post( array( 'post_content' => $content ) );

		$output = $this->blocks_of( $id );

		$this->assertSame( PostReader::MAX_BLOCKS, $output['block_count'] );
		$this->assertTrue( $output['truncated'] );
		$this->assertCount( PostReader::MAX_BLOCKS, $output['blocks'] );
	}

	public function test_nesting_deeper_than_max_depth_is_cut_and_reported(): void {
		$this->act_as_agent();
		$depth   = PostReader::MAX_DEPTH + 5;
		$content = str_repeat( '<!-- wp:group -->', $depth ) . '<p>Deep</p>' . str_repeat( '<!-- /wp:group -->', $depth );
		$id      = $this->admin_post( array( 'post_content' => $content ) );

		$output = $this->blocks_of( $id );

		$this->assertTrue( $output['truncated'] );
		$this->assertSame( PostReader::MAX_DEPTH, $output['block_count'], 'Levels 0 to MAX_DEPTH - 1 are returned.' );

		$node   = $output['blocks'][0];
		$levels = 1;
		while ( array() !== $node['inner_blocks'] ) {
			$node = $node['inner_blocks'][0];
			++$levels;
		}
		$this->assertSame( PostReader::MAX_DEPTH, $levels );
		$this->assertSame( str_repeat( '0.', PostReader::MAX_DEPTH - 1 ) . '0', $node['path'] );
	}

	public function test_the_total_size_of_block_html_is_capped(): void {
		$this->act_as_agent();
		$paragraph = '<!-- wp:paragraph --><p>' . str_repeat( "\xe2\x82\xac", 60000 ) . '</p><!-- /wp:paragraph -->';
		$content   = $paragraph . "\n" . $paragraph . "\n" . $paragraph;
		$id        = $this->admin_post( array( 'post_content' => $content ) );

		$output = $this->blocks_of( $id );

		$total = 0;
		foreach ( $output['blocks'] as $block ) {
			$total += strlen( $block['inner_html'] );
		}
		$this->assertTrue( $output['truncated'] );
		$this->assertLessThanOrEqual( PostReader::MAX_CONTENT_BYTES, $total );
		$this->assertSame( 1, preg_match( '//u', $output['blocks'][0]['inner_html'] ) );
		$this->assertSame( 3, $output['block_count'], 'Every block stays in the response, with its HTML cut to fit.' );
	}

	public function test_trashed_missing_disallowed_and_revision_ids_get_the_same_refusal(): void {
		$this->act_as_agent();

		$texts = $this->single_post_refusal_texts( self::TOOL_NAME );

		$this->assertCount( 1, array_unique( array_values( $texts ) ), 'Every refusal must read the same: ' . wp_json_encode( $texts ) );
	}

	public function test_an_agent_without_steadhand_read_is_refused(): void {
		$this->act_as_agent( array( \Steadhand\Capabilities::READ => false ) );
		$id = $this->admin_post( array( 'post_content' => self::CONTENT ) );

		$this->assertStringContainsString( 'steadhand_read', $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $id ) ) ) );
	}
}
