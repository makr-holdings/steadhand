<?php
/**
 * S7: a write whose execute() throws may have changed the post, and the caller and the log say so.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Integration tests for a write whose execute() throws (S7).
 */
final class WriteThrowTest extends PostWriteTestCase {

	/**
	 * The text for a write whose execute() threw.
	 */
	private const WRITE_THROW = 'The post was changed, but the save did not finish as expected, so the change was at least partly applied. Check the post before you continue.';

	public function test_a_listener_that_throws_after_the_row_is_written_is_reported_as_possibly_partly_applied(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'old',
			)
		);
		$agent = $this->act_as_agent();
		// Core also fires save_post for the revision saved first, so the listener throws for this post only. A throw during
		// the revision would be a snapshot failure, which keeps the generic text.
		add_action(
			'save_post',
			static function ( int $id ) use ( $post ): void {
				if ( $id === $post ) {
					throw new \RuntimeException( 'secret-listener-text' );
				}
			},
			999
		);

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'new',
			)
		);

		$this->assertSame( 'steadhand_update_partial', $this->refusal_code( $result ) );
		$this->assertSame( self::WRITE_THROW, $result->get_error_message() );
		$this->assertStringNotContainsString( 'secret-listener-text', (string) wp_json_encode( $result->get_error_data() ) );
		$this->assertSame( 'new', get_post( $post )->post_content, 'The row was written before the throw, so the change is partly applied.' );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'error', $row['result'] );
		$this->assertSame( self::WRITE_THROW, $row['message'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}
}
