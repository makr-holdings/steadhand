<?php
/**
 * The after-state must hold the text this write stored: races with another request, a same-request rewrite, slashes,
 * and the warning that a change could not be undone.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Pipeline\PostWriter;
use Steadhand\Pipeline\Snapshots;

/**
 * Covers SPEC §7 (the state after a write). A foreign write is simulated with a direct row update, as another request
 * would leave it, and fired from core's wp_after_insert_post action, at the point in the save where the race can happen.
 */
final class AfterStateTextTest extends PostWriteTestCase {

	private const UPDATE_TOOL = 'steadhand-update-post';
	private const EDIT_TOOL   = 'steadhand-edit-blocks';
	private const WARNING     = 'Steadhand could not save the state after this change, so it cannot be undone.';

	/**
	 * Hooks this file adds, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $hooks = array();

	/**
	 * The wp_insert_post_data filter that writes a foreign row during a revision insert, removed in tearDown.
	 *
	 * @var Closure|null
	 */
	private ?Closure $race_filter = null;

	protected function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_action( $hook[0], $hook[1], $hook[2] );
		}
		$this->hooks = array();

		if ( null !== $this->race_filter ) {
			remove_filter( 'wp_insert_post_data', $this->race_filter, PHP_INT_MAX - 1 );
			$this->race_filter = null;
		}

		if ( ! has_action( 'post_updated', 'wp_save_post_revision' ) ) {
			add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );
		}

		parent::tearDown();
	}

	private static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Runs $change the first time $hook fires for $target at $priority, and never again.
	 *
	 * @param string  $hook     Action name.
	 * @param int     $priority Priority.
	 * @param int     $target   Post whose hook fires count.
	 * @param Closure $change   What to do, given the post ID.
	 */
	private function once_during_save( string $hook, int $priority, int $target, Closure $change ): void {
		$fired    = false;
		$callback = static function ( int $post_id ) use ( &$fired, $target, $change ): void {
			if ( $fired || $post_id !== $target ) {
				return;
			}
			$fired = true;
			$change( $post_id );
		};

		add_action( $hook, $callback, $priority, 1 );
		$this->hooks[] = array( $hook, $callback, $priority );
	}

	/**
	 * A row update that another request would make: the post's content is set directly, with no revision.
	 *
	 * @param string $content New content.
	 */
	private function foreign_row_update( string $content ): Closure {
		return static function ( int $post_id ) use ( $content ): void {
			global $wpdb;

			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Another request's row write.
			clean_post_cache( $post_id );
		};
	}

	/**
	 * Asserts that the write was not undoable, and that the caller is told so.
	 *
	 * @param array<string, mixed> $output Tool output.
	 * @param int                  $agent  Agent user ID.
	 */
	private function assert_not_undoable( array $output, int $agent ): void {
		$row = $this->latest_row( $agent );
		$this->assertSame( 0, (int) $row['undoable'], 'The call must not be undoable.' );
		$this->assertSame( 0, (int) $row['after_snapshot_id'], 'No after-state may be named.' );
		$this->assertContains( self::WARNING, $output['warnings'] ?? array() );
	}

	/**
	 * Case (i): another request sets the row to FOREIGN after Steadhand's save and after core's revision of it. The
	 * after-side check must not save a revision of FOREIGN and name it.
	 */
	public function test_a_foreign_row_update_after_the_save_makes_an_update_post_call_not_undoable(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();
		$this->once_during_save( 'wp_after_insert_post', 99, $post, $this->foreign_row_update( 'FOREIGN' ) );

		$output = $this->output_of(
			self::UPDATE_TOOL,
			array(
				'id'      => $post,
				'content' => 'Written by Steadhand',
			)
		);

		$this->assertSame( 'FOREIGN', get_post( $post )->post_content, 'The other request\'s text must stay.' );
		$this->assert_not_undoable( $output, $agent );
		foreach ( $this->revision_ids( $post ) as $revision ) {
			$this->assertNotSame( 'FOREIGN', get_post( $revision )->post_content, 'Steadhand must not save a revision of the other request\'s text.' );
		}
	}

	/**
	 * Case (i) through edit-blocks: the same race, with the write made by the splice path.
	 */
	public function test_a_foreign_row_update_after_the_save_makes_an_edit_blocks_call_not_undoable(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ),
			)
		);
		$agent = $this->act_as_agent();
		$this->once_during_save( 'wp_after_insert_post', 99, $post, $this->foreign_row_update( 'FOREIGN' ) );

		$output = $this->output_of(
			self::EDIT_TOOL,
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

		$this->assertSame( 'FOREIGN', get_post( $post )->post_content, 'The other request\'s text must stay.' );
		$this->assert_not_undoable( $output, $agent );
	}

	/**
	 * Case (ii): the other request's row update lands before core saves the post-save revision, so Steadhand's own
	 * revision of the row holds FOREIGN.
	 */
	public function test_a_foreign_row_update_before_core_saves_the_revision_makes_an_update_post_call_not_undoable(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();
		$this->once_during_save( 'wp_after_insert_post', 1, $post, $this->foreign_row_update( 'FOREIGN' ) );

		$output = $this->output_of(
			self::UPDATE_TOOL,
			array(
				'id'      => $post,
				'content' => 'Written by Steadhand',
			)
		);

		$this->assertSame( 'FOREIGN', get_post( $post )->post_content, 'The other request\'s text must stay.' );
		$this->assert_not_undoable( $output, $agent );
	}

	/**
	 * Case (ii) through edit-blocks.
	 */
	public function test_a_foreign_row_update_before_core_saves_the_revision_makes_an_edit_blocks_call_not_undoable(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ),
			)
		);
		$agent = $this->act_as_agent();
		$this->once_during_save( 'wp_after_insert_post', 1, $post, $this->foreign_row_update( 'FOREIGN' ) );

		$output = $this->output_of(
			self::EDIT_TOOL,
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

		$this->assertSame( 'FOREIGN', get_post( $post )->post_content, 'The other request\'s text must stay.' );
		$this->assert_not_undoable( $output, $agent );
	}

	/**
	 * Another request writes the same text Steadhand wrote. The row and the stored text agree, so the call is undoable and
	 * its after-state holds that text.
	 */
	public function test_a_concurrent_save_of_the_same_text_leaves_the_call_undoable(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();
		$this->once_during_save( 'wp_after_insert_post', 99, $post, $this->foreign_row_update( 'Written by Steadhand' ) );

		$this->output_of(
			self::UPDATE_TOOL,
			array(
				'id'      => $post,
				'content' => 'Written by Steadhand',
			)
		);

		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$this->assertGreaterThan( 0, (int) $row['after_snapshot_id'] );
		$this->assertSame( 'Written by Steadhand', get_post( (int) $row['after_snapshot_id'] )->post_content );
	}

	/**
	 * The same request rewrites the post through the normal API after Steadhand's save. The row no longer holds what
	 * Steadhand wrote, so the call is not undoable.
	 */
	public function test_a_same_request_rewrite_after_the_save_makes_the_call_not_undoable(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();
		$this->once_during_save(
			'wp_after_insert_post',
			99,
			$post,
			static function ( int $post_id ): void {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => 'Rewritten by a plugin',
					)
				);
			}
		);

		$output = $this->output_of(
			self::UPDATE_TOOL,
			array(
				'id'      => $post,
				'content' => 'Written by Steadhand',
			)
		);

		$this->assert_not_undoable( $output, $agent );
	}

	/**
	 * A same-request rewrite back to the before-text. A new revision holds the before-text, but it is not the text this
	 * write stored, and it equals the before-revision, so the call is not undoable.
	 */
	public function test_a_same_request_rewrite_back_to_the_before_text_makes_the_call_not_undoable(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();
		$this->once_during_save(
			'wp_after_insert_post',
			99,
			$post,
			static function ( int $post_id ): void {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => 'Before',
					)
				);
			}
		);

		$output = $this->output_of(
			self::UPDATE_TOOL,
			array(
				'id'      => $post,
				'content' => 'Written by Steadhand',
			)
		);

		$this->assert_not_undoable( $output, $agent );
	}

	/**
	 * Content with backslashes, quotes and a trailing backslash: the write is undoable, and its after-state is the row
	 * byte for byte.
	 */
	public function test_content_with_slashes_and_quotes_is_undoable_and_its_after_state_is_the_row(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();
		$text  = 'He said "hi" and it\'s C:\\dir\\ then \\\\ and \\\'quoted\\\' \\"x\\" end\\';

		$this->output_of(
			self::UPDATE_TOOL,
			array(
				'id'      => $post,
				'content' => $text,
			)
		);

		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$after = (int) $row['after_snapshot_id'];
		$this->assertGreaterThan( 0, $after );
		$this->assertSame( (string) get_post( $post )->post_content, (string) get_post( $after )->post_content );
		$this->assertSame( (string) get_post( $post )->post_content, get_post( $after )->post_content );
	}

	/**
	 * Slashes through edit-blocks: a normal edit of a paragraph that contains backslashes and quotes is undoable.
	 */
	public function test_an_edit_blocks_edit_with_slashes_is_undoable_and_its_after_state_is_the_row(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ),
			)
		);
		$agent = $this->act_as_agent();

		$this->output_of(
			self::EDIT_TOOL,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => self::paragraph( 'Say "hi" C:\\dir\\ and \\\\ end\\' ),
					),
				),
			)
		);

		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$after = (int) $row['after_snapshot_id'];
		$this->assertGreaterThan( 0, $after );
		$this->assertSame( (string) get_post( $post )->post_content, (string) get_post( $after )->post_content );
	}

	/**
	 * A normal edit through edit-blocks is undoable, and its after-state holds the text the write stored.
	 */
	public function test_a_normal_edit_blocks_edit_is_undoable(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => self::paragraph( 'One' ) . "\n\n" . self::paragraph( 'Two' ),
			)
		);
		$agent = $this->act_as_agent();

		$this->output_of(
			self::EDIT_TOOL,
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

		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$this->assertSame( (string) get_post( $post )->post_content, (string) get_post( (int) $row['after_snapshot_id'] )->post_content );
	}

	/**
	 * Core does not save the post-save revision, because a plugin removed its hook. The after-state is then saved by the
	 * after-side check, and the call is still undoable when the row holds the text Steadhand wrote.
	 */
	public function test_a_call_is_undoable_when_core_does_not_save_the_post_save_revision(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();
		remove_action( 'post_updated', 'wp_save_post_revision', 10 );

		$this->output_of(
			self::UPDATE_TOOL,
			array(
				'id'      => $post,
				'content' => 'Written by Steadhand',
			)
		);

		$row = $this->latest_row( $agent );
		$this->assertSame( 1, (int) $row['undoable'] );
		$this->assertSame( 'Written by Steadhand', get_post( (int) $row['after_snapshot_id'] )->post_content );
	}

	/**
	 * The text a write stored is recorded for the post, and the next capture of that post clears it. Another post is
	 * never recorded.
	 */
	public function test_the_written_text_is_the_stored_text_and_the_next_capture_clears_it(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Title \\ one',
				'post_content' => 'Before',
			)
		);
		$other = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent();
		$text = 'Written \\"quoted\\" and C:\\dir\\';

		$result = $this->in_write(
			static function () use ( $post, $other, $text ): array {
				Snapshots::post_revision( $post );
				PostWriter::update( $post, array( 'post_content' => $text ) );
				$recorded = PostWriter::written_text( $post );

				$after_capture = Snapshots::post_revision( $post );
				$cleared       = PostWriter::written_text( $post );

				return array(
					'recorded'      => $recorded,
					'other'         => PostWriter::written_text( $other ),
					'cleared'       => $cleared,
					'after_capture' => $after_capture,
				);
			}
		);

		$stored = get_post( $post );
		$this->assertSame(
			array(
				'post_title'   => $stored->post_title,
				'post_content' => $stored->post_content,
				'post_excerpt' => $stored->post_excerpt,
			),
			$result['recorded']
		);
		$this->assertSame( $text, $result['recorded']['post_content'] );
		$this->assertNull( $result['other'] );
		$this->assertNull( $result['cleared'] );
	}

	/**
	 * Arms a row update that lands during the next revision insert of the post. The update runs inside the insert, after the
	 * row check and before the revision mirror reads the row, so the revision takes the other request's text. It runs once.
	 *
	 * @param int $post Post ID.
	 */
	private function foreign_row_during_next_revision_insert( int $post ): void {
		$fired             = false;
		$this->race_filter = static function ( array $data ) use ( $post, &$fired ): array {
			if ( $fired || 'revision' !== ( $data['post_type'] ?? null ) || (int) ( $data['post_parent'] ?? 0 ) !== $post ) {
				return $data;
			}

			$fired = true;
			global $wpdb;

			$wpdb->update( $wpdb->posts, array( 'post_content' => 'FOREIGN' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Another request's row write.
			clean_post_cache( $post );

			return $data;
		};

		// Below PHP_INT_MAX, so it runs before the revision mirror, which reads the row when the revision is inserted.
		add_filter( 'wp_insert_post_data', $this->race_filter, PHP_INT_MAX - 1, 4 );
	}

	/**
	 * A row change between the after-side row check and the revision insert: the revision saved there holds the other text.
	 * Snapshots rejects it, because the revision does not hold the written text, so the call is not undoable and names no
	 * after-state. The revision is saved, and it is never named.
	 */
	public function test_a_foreign_row_update_during_the_after_side_revision_insert_is_not_named(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();
		remove_action( 'post_updated', 'wp_save_post_revision', 10 );
		$arm = function ( int $post_id ): void {
			$this->foreign_row_during_next_revision_insert( $post_id );
		};
		$this->once_during_save( 'wp_after_insert_post', 1, $post, $arm );

		$output = $this->output_of(
			self::UPDATE_TOOL,
			array(
				'id'      => $post,
				'content' => 'Written by Steadhand',
			)
		);

		$this->assertSame( 'FOREIGN', get_post( $post )->post_content, 'The other request\'s text must stay.' );
		$this->assert_not_undoable( $output, $agent );

		// The real behaviour: the revision saved by the after-side check holds the other text, and is not named.
		$holding = array_filter(
			$this->revision_ids( $post ),
			static fn( int $id ): bool => 'FOREIGN' === get_post( $id )->post_content
		);
		$this->assertNotEmpty( $holding, 'The after-side save must have made a revision that holds the other text.' );
	}

	/**
	 * A write that sets status is not undoable, and so no capture is opened for it. The text it recorded is still cleared
	 * once the write ends.
	 */
	public function test_the_written_text_is_cleared_after_a_write_that_sets_status(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Before',
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $post,
				'status' => 'private',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'private', get_post( $post )->post_status );
		$this->assertNull( PostWriter::written_text( $post ), 'The text of a finished write must not be kept.' );
	}

	/**
	 * A write that returns a WP_Error after it saved the row does not keep the text it recorded.
	 */
	public function test_the_written_text_is_cleared_after_a_failed_write(): void {
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent();

		$probe             = new WriteProbeAbility();
		$probe->on_execute = static function () use ( $post ): \WP_Error {
			PostWriter::update( $post, array( 'post_content' => 'Written, then refused' ) );

			return new \WP_Error( 'steadhand_test_failure', 'Failed after the save.' );
		};

		$out = ( new Pipeline( new DbLogger() ) )->run( $probe, array() );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'Written, then refused', get_post( $post )->post_content, 'The save did happen.' );
		$this->assertNull( PostWriter::written_text( $post ) );
	}

	/**
	 * A write whose execute() throws after it saved the row does not keep the text it recorded.
	 */
	public function test_the_written_text_is_cleared_after_a_write_whose_execute_throws(): void {
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent();

		$probe             = new WriteProbeAbility();
		$probe->on_execute = static function () use ( $post ): array {
			PostWriter::update( $post, array( 'post_content' => 'Written, then thrown' ) );

			throw new \RuntimeException( 'Thrown after the save.' );
		};

		$out = ( new Pipeline( new DbLogger() ) )->run( $probe, array() );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'Written, then thrown', get_post( $post )->post_content, 'The save did happen.' );
		$this->assertNull( PostWriter::written_text( $post ) );
	}
}
