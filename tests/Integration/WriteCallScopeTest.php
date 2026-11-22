<?php
/**
 * S1: edit_post and publish_post are mapped only inside a write call, and the innermost call decides.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Integration tests for the write-call scope of the post mapping (S1).
 */
final class WriteCallScopeTest extends PostWriteTestCase {

	use InsideCall;

	/**
	 * Removes the shortcode the tests add.
	 */
	protected function tearDown(): void {
		remove_shortcode( 'steadhand_scope_probe' );
		unset( $GLOBALS['steadhand_scope_seen'] );
		parent::tearDown();
	}

	public function test_inside_a_read_tool_a_shortcode_gets_no_edit_or_publish_answer_even_for_an_agent_holding_both(): void {
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$live  = $this->admin_post( array( 'post_status' => 'publish' ) );
		add_shortcode(
			'steadhand_scope_probe',
			static function () use ( $draft, $live ): string {
				$GLOBALS['steadhand_scope_seen'] = array(
					'edit_draft' => current_user_can( 'edit_post', $draft ),
					'edit_live'  => current_user_can( 'edit_post', $live ),
					'publish'    => current_user_can( 'publish_post', $draft ),
					'edit_link'  => (string) get_edit_post_link( $draft, 'raw' ),
					'write_call' => Pipeline::is_write_call(),
				);

				return 'x';
			}
		);
		$host = $this->admin_post( array( 'post_content' => '[steadhand_scope_probe]' ) );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$result = $this->execute_ability( 'steadhand/get-rendered', array( 'id' => $host ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'edit_draft' => false,
				'edit_live'  => false,
				'publish'    => false,
				'edit_link'  => '',
				'write_call' => false,
			),
			$GLOBALS['steadhand_scope_seen'] ?? null,
			'Inside a read tool, the shortcode sees core\'s answers.'
		);
	}

	public function test_inside_a_write_call_the_same_agent_gets_the_mapped_answers(): void {
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$live  = $this->admin_post( array( 'post_status' => 'publish' ) );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$answers = $this->inside_write_call(
			static fn(): array => array(
				'edit_draft' => current_user_can( 'edit_post', $draft ),
				'edit_live'  => current_user_can( 'edit_post', $live ),
				'publish'    => current_user_can( 'publish_post', $draft ),
			)
		);

		$this->assertSame(
			array(
				'edit_draft' => true,
				'edit_live'  => true,
				'publish'    => true,
			),
			$answers
		);
	}

	public function test_a_read_call_nested_in_a_write_call_gets_core_answers_and_the_write_answers_return_afterwards(): void {
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$answers = $this->inside_write_call(
			function () use ( $draft ): array {
				$inner = $this->inside_read_call( static fn(): bool => current_user_can( 'edit_post', $draft ) );
				return array(
					'nested_read' => $inner,
					'after'       => current_user_can( 'edit_post', $draft ),
				);
			}
		);

		$this->assertFalse( $answers['nested_read'], 'The innermost call is a read, so the mapping is off.' );
		$this->assertTrue( $answers['after'], 'The write call again answers the mapping once the read has returned.' );
	}

	public function test_a_write_call_nested_in_a_read_call_gets_the_mapped_answers_and_the_read_answers_return_afterwards(): void {
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$answers = $this->inside_read_call(
			function () use ( $draft ): array {
				$inner = $this->inside_write_call( static fn(): bool => current_user_can( 'edit_post', $draft ) );
				return array(
					'nested_write' => $inner,
					'after'        => current_user_can( 'edit_post', $draft ),
				);
			}
		);

		$this->assertTrue( $answers['nested_write'], 'The innermost call is a write, so the mapping is on.' );
		$this->assertFalse( $answers['after'], 'The read call again answers core once the write has returned.' );
	}

	public function test_after_every_exit_path_the_write_scope_is_off_again(): void {
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $draft,
				'content' => 'ok',
			)
		);
		$this->assertFalse( Pipeline::is_write_call() );
		$this->assertFalse( current_user_can( 'edit_post', $draft ) );

		$this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => 999999999,
				'content' => 'refused',
			)
		);
		$this->assertFalse( Pipeline::is_write_call() );

		$this->execute_ability( 'steadhand/update-post', array( 'id' => $draft ) );
		$this->assertFalse( Pipeline::is_write_call() );

		$this->inside_write_call( static fn(): bool => true );
		$this->assertFalse( Pipeline::is_write_call(), 'A write probe leaves nothing behind.' );
		$this->assertFalse( current_user_can( 'publish_post', $draft ) );
	}
}
