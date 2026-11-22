<?php
/**
 * The update ability re-checks the object before it writes, and keeps each call's state apart from a nested call.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Registry;
use Steadhand\Guardrails\Result;

/**
 * Integration tests for the update ability re-checking its object and keeping calls apart.
 */
final class UpdatePostReentryTest extends PostWriteTestCase {

	/**
	 * The warning for a call that sets status, slug or date.
	 */
	private const METADATA_WARNING = 'This change includes status, slug or date, which Steadhand cannot undo yet.';

	public function test_execute_refuses_when_a_guardrail_made_the_draft_live_before_the_write_and_nothing_changes(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'draft text',
			)
		);
		$this->act_as_agent();
		$seen_after_flip = null;
		add_action(
			'steadhand_register_guardrails',
			function ( Registry $registry ) use ( $post, &$seen_after_flip ): void {
				$registry->add(
					new FakeRule(
						'zz/flip-status',
						function ( Context $context ) use ( $post, &$seen_after_flip ): Result {
							unset( $context );
							global $wpdb;
							$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Makes the post live between the check and the write.
							clean_post_cache( $post );
							$seen_after_flip = $this->row( $post );

							return Result::allow();
						}
					)
				);
			}
		);

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'agent wrote',
			)
		);

		// execute() re-checks the object and refuses with the same not-found error an unknown post gets.
		$this->assertSame( 'steadhand_not_found', $this->refusal_code( $result ) );
		$this->assertIsArray( $seen_after_flip );
		$this->assertSame( $seen_after_flip, $this->row( $post ), 'The write must not run.' );
		$this->assertSame( 'draft text', get_post( $post )->post_content );
	}

	public function test_a_nested_update_of_another_post_from_save_post_leaves_the_outer_warnings_and_snapshot_correct(): void {
		$outer  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'a1',
			)
		);
		$inner  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'b1',
			)
		);
		$agent  = $this->act_as_agent( array( 'steadhand_publish' => true ) );
		$fired  = false;
		$nested = null;
		add_action(
			'save_post',
			function ( int $id ) use ( $outer, $inner, &$fired, &$nested ): void {
				if ( $id !== $outer || $fired ) {
					return;
				}
				$fired  = true;
				$nested = $this->execute_ability(
					'steadhand/update-post',
					array(
						'id'      => $inner,
						'content' => 'b2',
					)
				);
			}
		);

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $outer,
				'content' => 'a2',
				'slug'    => 'outer-slug-kept',
			)
		);

		$this->assertTrue( $fired );
		$this->assertIsArray( $nested );
		$this->assertIsArray( $out );
		$this->assertSame( array( self::METADATA_WARNING ), $out['warnings'], 'The outer call keeps its own warning.' );

		global $wpdb;
		$table     = \Steadhand\Log\Schema::table();
		$outer_row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND object_id = %d ORDER BY id DESC LIMIT 1", $agent, $outer ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the outer call's row.
		$inner_row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND object_id = %d ORDER BY id DESC LIMIT 1", $agent, $inner ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the nested call's row.

		$this->assertSame( 'ok', $outer_row['result'] );
		$this->assertGreaterThan( 0, (int) $outer_row['snapshot_id'] );
		$this->assertSame( 0, (int) $outer_row['undoable'], 'The outer call changed its slug, so it is not undoable.' );
		$this->assertSame( 1, (int) $inner_row['undoable'], 'The nested call changed only content.' );
	}
}
