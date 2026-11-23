<?php
/**
 * Tests that a Steadhand refusal over MCP ends with its code, and that the change log keeps the bare message.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Registry;
use WP_Error;

/**
 * The MCP Adapter sends only the text of a WP_Error, so Registry::with_code() appends " (<code>)" to it. These tests
 * check that over MCP, that the change log does not store the suffix, and the rules of with_code() itself.
 */
final class RefusalCodeTest extends PostWriteTestCase {

	private const GET_TOOL   = 'steadhand-get-post';
	private const EDIT_TOOL  = 'steadhand-edit-blocks';
	private const MISSING_ID = 987654321;
	private const PARAGRAPH  = "<!-- wp:paragraph -->\n<p>Old</p>\n<!-- /wp:paragraph -->";
	private const HEADING    = "<!-- wp:heading -->\n<h2>New</h2>\n<!-- /wp:heading -->";

	public function test_a_permission_refusal_over_mcp_ends_with_its_code(): void {
		$this->act_as_agent();

		$result = $this->tool_result( self::GET_TOOL, array( 'id' => self::MISSING_ID ) );

		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame(
			'Not found, or this agent may not access it. (steadhand_forbidden)',
			$result['content'][0]['text'],
			'The full text is the message, a space, and the code in parentheses.'
		);
	}

	public function test_a_refusal_from_the_execute_path_over_mcp_ends_with_its_own_code(): void {
		$this->act_as_agent();
		$post = $this->admin_post(
			array(
				'post_content' => self::PARAGRAPH,
				'post_status'  => 'draft',
			)
		);
		$args = array(
			'id'         => $post,
			'operations' => array(
				array(
					'op'     => 'replace',
					'path'   => '0',
					'name'   => 'core/heading',
					'markup' => self::HEADING,
				),
			),
		);

		$result = $this->tool_result( self::EDIT_TOOL, $args );

		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'steadhand_refused_block_mismatch', $this->refusal_code( $result ) );
		$this->assertStringEndsWith( ' (steadhand_refused_block_mismatch)', $result['content'][0]['text'] );
		$this->assertSame( self::PARAGRAPH, get_post( $post )->post_content, 'The refused call writes nothing.' );
	}

	public function test_the_change_log_stores_the_message_without_the_code(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post(
			array(
				'post_content' => self::PARAGRAPH,
				'post_status'  => 'draft',
			)
		);

		$result = $this->tool_result(
			self::EDIT_TOOL,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '0',
						'name'   => 'core/heading',
						'markup' => self::HEADING,
					),
				),
			)
		);

		$bare = $this->refusal_text( $result );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'steadhand/edit-blocks', $row['ability'] );
		$this->assertSame( $bare, $row['message'], 'The log stores the message the caller would have seen before the code was added.' );
		$this->assertStringNotContainsString( '(steadhand_', $row['message'] );
	}

	public function test_a_successful_call_over_mcp_is_unchanged(): void {
		$this->act_as_agent();
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );

		$result = $this->tool_result( self::GET_TOOL, array( 'id' => $post ) );

		$this->assertFalse( $result['isError'] );
		$this->assertSame( $post, $result['structuredContent']['id'] );
		$this->assertStringNotContainsString( '(steadhand_', (string) $result['content'][0]['text'] );
	}

	public function test_with_code_returns_a_value_that_is_not_an_error_unchanged(): void {
		$array = array( 'id' => 1 );

		$this->assertSame( $array, Registry::with_code( $array ) );
		$this->assertTrue( Registry::with_code( true ) );
		$this->assertNull( Registry::with_code( null ) );
	}

	public function test_with_code_returns_an_error_with_another_code_unchanged(): void {
		$error = new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.' );

		$this->assertSame( $error, Registry::with_code( $error ) );
		$this->assertSame( 'Sorry, you are not allowed to do that.', $error->get_error_message() );
	}

	public function test_with_code_appends_the_code_to_a_steadhand_error_and_keeps_its_code_and_data(): void {
		$error = new WP_Error( 'steadhand_forbidden', 'Nope.', array( 'status' => 403 ) );

		$wrapped = Registry::with_code( $error );

		$this->assertNotSame( $error, $wrapped );
		$this->assertInstanceOf( WP_Error::class, $wrapped );
		$this->assertSame( 'steadhand_forbidden', $wrapped->get_error_code() );
		$this->assertSame( 'Nope. (steadhand_forbidden)', $wrapped->get_error_message() );
		$this->assertSame( array( 'status' => 403 ), $wrapped->get_error_data() );
		$this->assertSame( 'Nope.', $error->get_error_message(), 'The original error is not changed.' );
	}

	public function test_with_code_does_not_add_the_code_twice(): void {
		$error = new WP_Error( 'steadhand_forbidden', 'Nope.' );

		$once  = Registry::with_code( $error );
		$twice = Registry::with_code( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( 'Nope. (steadhand_forbidden)', $twice->get_error_message() );
	}

	public function test_with_code_leaves_a_code_with_odd_characters_unchanged(): void {
		$error = new WP_Error( 'steadhand_<script>', 'Nope.' );

		$this->assertSame( $error, Registry::with_code( $error ) );
		$this->assertSame( 'Nope.', $error->get_error_message() );
	}
}
