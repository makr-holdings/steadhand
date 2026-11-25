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


	public function test_a_read_ability_checked_through_the_permission_path_is_not_a_write_call_and_edit_post_is_not_mapped(): void {
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent( array( Capabilities::WRITE_DRAFTS => true ) );
		$probe        = new ReadCheckProbe();
		$probe->draft = $draft;

		$allowed = ( new Pipeline( new SpyLogger() ) )->check( $probe, array() );

		$this->assertTrue( $allowed, 'The probe must reach its object check.' );
		$this->assertFalse( $probe->write_call, 'A read call is not a write call, however it is checked.' );
		$this->assertFalse( $probe->edit_answer, 'edit_post is not mapped for a read call.' );
	}

	/**
	 * Runs a probe as a call of its own name, through a Pipeline, and returns what run() returned.
	 *
	 * @param NamedProbe $probe The probe.
	 * @return mixed
	 */
	private function run_probe( NamedProbe $probe ): mixed {
		return ( new Pipeline( new SpyLogger() ) )->run( $probe, array() );
	}

	/**
	 * Current_ability_name() is '' outside a call, and after a normal call the name is cleared again.
	 */
	public function test_current_ability_name_is_empty_after_a_normal_call(): void {
		$this->act_as_agent();

		$result = $this->run_probe( new NamedProbe( 'steadhand/get-post', static fn(): string => Pipeline::current_ability_name(), false ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'steadhand/get-post', $result['answer'], 'The call sees its own name.' );
		$this->assertSame( '', Pipeline::current_ability_name() );
	}

	/**
	 * A call whose authorize_object() throws is refused, and the name is cleared on that exit path too.
	 */
	public function test_current_ability_name_is_empty_after_a_call_whose_authorize_object_throws(): void {
		$this->act_as_agent();

		try {
			$this->run_probe( new NamedProbe( 'steadhand/trash-post', static fn(): string => 'never', true, true ) );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'authorize_object', $e->getMessage() );
		}

		$this->assertSame( '', Pipeline::current_ability_name() );
	}

	/**
	 * A call whose execute() throws leaves the name cleared.
	 */
	public function test_current_ability_name_is_empty_after_a_call_whose_execute_throws(): void {
		$this->act_as_agent();

		try {
			$this->run_probe(
				new NamedProbe(
					'steadhand/get-post',
					static function (): string {
						throw new \RuntimeException( 'The probe throws in execute().' );
					},
					false
				)
			);
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'execute', $e->getMessage() );
		}

		$this->assertSame( '', Pipeline::current_ability_name() );
	}

	/**
	 * Inside a nested call the name is the inner call's, and after the inner call returns it is the outer call's name again. After the
	 * outer call it is cleared.
	 */
	public function test_current_ability_name_reports_the_inner_name_during_a_nested_call_and_the_outer_name_after_it(): void {
		$this->act_as_agent();

		$outer = $this->run_probe(
			new NamedProbe(
				'steadhand/trash-post',
				function (): array {
					$inner = $this->run_probe( new NamedProbe( 'steadhand/get-post', static fn(): string => Pipeline::current_ability_name(), false ) );

					return array(
						'inner' => $inner['answer'],
						'outer' => Pipeline::current_ability_name(),
					);
				},
				true
			)
		);

		$this->assertIsArray( $outer );
		$this->assertSame( 'steadhand/get-post', $outer['answer']['inner'], 'The inner call reports its own name.' );
		$this->assertSame( 'steadhand/trash-post', $outer['answer']['outer'], 'After the inner call the outer name is back.' );
		$this->assertSame( '', Pipeline::current_ability_name() );
	}
}
