<?php
/**
 * The after-state lookup: the capture of revisions made during a write, and what post_revision_after() accepts.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\PostWriter;
use Steadhand\Pipeline\Snapshots;

/**
 * Covers SPEC §7 (the state after a write). Calls run through the real update-post ability or inside a write probe, so
 * Pipeline::is_writing() is true, as it is in production.
 */
final class SnapshotsTest extends PostWriteTestCase {

	private const TOOL_NAME = 'steadhand-update-post';

	/**
	 * A write that never called post_revision() for the post has no capture, so there is no after-state to name, even for
	 * a valid before-revision of that post.
	 */
	public function test_a_write_that_never_called_post_revision_gets_no_after_state(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'One',
			)
		);
		$agent = $this->act_as_agent();
		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'      => $post,
				'content' => 'Two',
			)
		);
		$older = (int) $this->latest_row( $agent )['snapshot_id'];
		$this->assertGreaterThan( 0, $older );

		$after = $this->in_write( static fn(): int => Snapshots::post_revision_after( $post, $older ) );

		$this->assertSame( 0, $after );
	}

	/**
	 * Once a write has ended, its capture is closed. A later check for the same post finds nothing, even with the same
	 * before-revision that the write used. The check runs in a probe write, so only a capture left open by the earlier
	 * write could make it succeed.
	 */
	public function test_no_capture_remains_after_a_write(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'One',
			)
		);
		$agent = $this->act_as_agent();
		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'      => $post,
				'content' => 'Two',
			)
		);

		$row    = $this->latest_row( $agent );
		$before = (int) $row['snapshot_id'];
		$after  = (int) $row['after_snapshot_id'];
		$this->assertGreaterThan( 0, $after, 'The write itself named an after-state.' );

		// The text is recorded again first, so only an open capture from the earlier write could name a revision here. The forgotten
		// text alone would make the check return 0.
		$later = $this->in_write(
			static function () use ( $post, $before ): int {
				PostWriter::update( $post, array( 'post_content' => get_post( $post )->post_content ) );

				return Snapshots::post_revision_after( $post, $before );
			}
		);

		$this->assertSame( 0, $later, 'The capture must be closed when the write ends.' );
	}

	/**
	 * A second check in the same write finds the capture closed by the first, so it returns 0 even though the revision it
	 * would name is valid. The capture is ended on every path, the normal one included.
	 */
	public function test_a_second_check_in_the_same_write_finds_the_capture_closed(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$this->act_as_agent();
		$result = $this->in_write(
			function () use ( $post ): array {
				$before = Snapshots::post_revision( $post );
				PostWriter::update( $post, array( 'post_content' => 'After' ) );
				$first = Snapshots::post_revision_after( $post, $before );

				// The text is recorded again, so only an open capture could name the revision the first check named.
				PostWriter::update( $post, array( 'post_content' => 'After' ) );

				return array(
					'first'  => $first,
					'second' => Snapshots::post_revision_after( $post, $before ),
				);
			}
		);

		$this->assertGreaterThan( 0, $result['first'] );
		$this->assertSame( 0, $result['second'] );
	}

	/**
	 * A revision of another post, made during the write, is never the after-state of this post, and the other post has no
	 * capture, so nothing it did is recorded for it.
	 */
	public function test_a_revision_of_another_post_made_during_the_write_is_not_accepted(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'A before',
			)
		);
		$other = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'B before',
			)
		);

		$this->act_as_agent();
		$result = $this->in_write(
			function () use ( $post, $other ): array {
				$before = Snapshots::post_revision( $post );
				PostWriter::update( $post, array( 'post_content' => 'A after' ) );
				wp_update_post(
					array(
						'ID'           => $other,
						'post_content' => 'B after',
					)
				);

				return array(
					'before' => $before,
					'after'  => Snapshots::post_revision_after( $post, $before ),
					'other'  => Snapshots::post_revision_after( $other, 0 ),
				);
			}
		);

		$this->assertGreaterThan( $result['before'], $result['after'] );
		$this->assertSame( $post, (int) get_post( $result['after'] )->post_parent );
		$this->assertSame( 'A after', get_post( $result['after'] )->post_content );

		$other_revisions = $this->revision_ids( $other );
		$this->assertNotContains( $result['after'], $other_revisions );
		$this->assertSame( 0, $result['other'], 'The other post had no capture, so nothing of it is accepted.' );
	}

	public function test_post_revision_after_refuses_to_run_outside_a_write(): void {
		$this->expectException( \LogicException::class );

		Snapshots::post_revision_after( 1, 1 );
	}
}
