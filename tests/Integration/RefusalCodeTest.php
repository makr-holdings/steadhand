<?php
/**
 * Tests that a Steadhand refusal over MCP ends with its code, and that the change log keeps the bare message.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Registry;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Registry as GuardrailRegistry;
use Steadhand\Guardrails\Result;
use WP_Error;

/**
 * The MCP Adapter sends only the text of a WP_Error, so Registry::with_code() appends " (<code>)" to it. These tests
 * check that over MCP, that the change log does not store the suffix, and the rules of with_code() itself.
 */
final class RefusalCodeTest extends PostWriteTestCase {

	private const GET_TOOL    = 'steadhand-get-post';
	private const EDIT_TOOL   = 'steadhand-edit-blocks';
	private const CREATE_TOOL = 'steadhand-create-post';
	private const MISSING_ID  = 987654321;
	private const PARAGRAPH   = "<!-- wp:paragraph -->\n<p>Old</p>\n<!-- /wp:paragraph -->";
	private const HEADING     = "<!-- wp:heading -->\n<h2>New</h2>\n<!-- /wp:heading -->";

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

	public function test_with_code_keeps_only_the_first_code_message_and_data_of_an_error_with_two_codes(): void {
		$error = new WP_Error( 'steadhand_forbidden', 'First.', array( 'status' => 403 ) );
		$error->add( 'steadhand_invalid_input', 'Second.', array( 'status' => 400 ) );

		$wrapped = Registry::with_code( $error );

		$this->assertSame( 'steadhand_forbidden', $wrapped->get_error_code() );
		$this->assertSame( 'First. (steadhand_forbidden)', $wrapped->get_error_message(), 'Only the first message is kept, with the first code.' );
		$this->assertSame( array( 'status' => 403 ), $wrapped->get_error_data() );
	}

	public function test_with_code_gives_an_empty_message_a_leading_space_before_the_code(): void {
		$error = new WP_Error( 'steadhand_forbidden', '' );

		// The suffix is " (<code>)", so an empty message becomes one space and the code in parentheses.
		$this->assertSame( ' (steadhand_forbidden)', Registry::with_code( $error )->get_error_message() );
	}

	public function test_a_guardrail_block_over_mcp_ends_with_its_code_and_the_log_keeps_the_bare_message(): void {
		$agent  = $this->act_as_agent();
		$result = $this->tool_result(
			self::CREATE_TOOL,
			array(
				'title'   => 'x',
				'content' => '<!-- wp:group --><p>x</p>',
			)
		);

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertStringEndsWith( ' (steadhand_guardrail_blocked)', $result['content'][0]['text'] );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'blocked', $row['result'] );
		$this->assertSame( $this->refusal_text( $result ), $row['message'], 'The log stores the rule message without the code.' );
		$this->assertStringNotContainsString( '(steadhand_', $row['message'] );
	}

	public function test_the_refusal_at_the_execute_re_check_has_the_same_text_and_code_as_the_permission_refusal(): void {
		$this->act_as_agent();
		$gone = $this->admin_post( array( 'post_status' => 'draft' ) );
		$late = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'draft text',
			)
		);
		wp_trash_post( $gone );

		// Trashes $late while the guardrails run, after the permission check has passed it and before execute() re-checks it.
		add_action(
			'steadhand_register_guardrails',
			function ( GuardrailRegistry $registry ) use ( $late ): void {
				$registry->add(
					new FakeRule(
						'zz/trash-late',
						function ( Context $context ) use ( $late ): Result {
							unset( $context );
							wp_trash_post( $late );

							return Result::allow();
						}
					)
				);
			}
		);
		$callbacks = $this->callbacks_for( 'steadhand/update-post' );

		$gone_args     = array(
			'id'      => $gone,
			'content' => 'agent wrote',
		);
		$at_permission = $callbacks['permission_callback']( $gone_args );
		$this->assertWPError( $at_permission );

		$args = array(
			'id'      => $late,
			'content' => 'agent wrote',
		);
		$this->assertTrue( $callbacks['permission_callback']( $args ), 'The post is readable when the permission check runs.' );

		$at_execute = $callbacks['execute_callback']( $args );
		$this->assertWPError( $at_execute );
		$this->assertSame( 'trash', get_post_status( $late ), 'The post must be trashed during the guardrails.' );
		$this->assertSame( 'draft text', get_post( $late )->post_content, 'The refused call writes nothing.' );

		$this->assertSame( 'steadhand_forbidden', $at_permission->get_error_code() );
		$this->assertSame( 'steadhand_forbidden', $at_execute->get_error_code(), 'The re-check must not show a different code.' );
		$this->assertSame( $at_permission->get_error_message(), $at_execute->get_error_message(), 'The re-check must not show a different text.' );
		$this->assertSame( 'Not found, or this agent may not access it. (steadhand_forbidden)', $at_execute->get_error_message() );
		$this->assertSame( 403, $at_execute->get_error_data()['status'] );
		$this->assertSame( $at_permission->get_error_data(), $at_execute->get_error_data() );
	}

	public function test_a_log_failure_returned_through_the_callbacks_ends_with_its_code(): void {
		$this->act_as_agent();
		$this->make_inserts_fail();
		$before    = $this->post_row_count();
		$callbacks = $this->callbacks_for( 'steadhand/create-post' );
		$args      = array( 'title' => 'x' );

		$this->assertTrue( $callbacks['permission_callback']( $args ) );
		$result = $callbacks['execute_callback']( $args );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
		$this->assertStringEndsWith( ' (steadhand_log_failed)', $result->get_error_message() );
		$this->assertSame( $before, $this->post_row_count(), 'A create that cannot be recorded must not run.' );
	}

	/**
	 * Returns the permission and execute callbacks of one ability, built the way the plugin builds them.
	 *
	 * @param string $name Ability name, e.g. steadhand/update-post.
	 * @return array{permission_callback: callable, execute_callback: callable}
	 */
	private function callbacks_for( string $name ): array {
		$registry = ( new \Steadhand\Plugin() )->registry();

		foreach ( $registry->abilities() as $ability ) {
			if ( $ability->name() === $name ) {
				return $registry->args_for( $ability );
			}
		}

		$this->fail( "No ability named $name." );
	}
}
