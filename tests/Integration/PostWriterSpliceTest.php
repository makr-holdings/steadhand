<?php
/**
 * PostWriter::assemble(), assemble_report() and splice(): segment validation, the write-call guards, the stored-content check,
 * the filters during a splice, and the fields a splice puts back.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\PostWriter;

/**
 * Covers the trust boundary of steadhand/edit-blocks. The save is the one update-post makes; these tests call PostWriter
 * directly, inside a write (see in_write()), so they can check what each helper accepts and what it stores.
 */
final class PostWriterSpliceTest extends PostWriteTestCase {

	/**
	 * The KSES filters a save adds for post fields. Removed by the test that checks the save scope.
	 */
	private const KSES_PAIRS = array(
		array( 'title_save_pre', 'wp_filter_kses' ),
		array( 'content_save_pre', 'wp_filter_post_kses' ),
		array( 'excerpt_save_pre', 'wp_filter_post_kses' ),
		array( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
	);

	/**
	 * Runs a callable inside a write, and returns what it returned, or the class name of the exception it threw.
	 *
	 * @param callable $work The code.
	 * @return mixed
	 */
	private function attempt( callable $work ): mixed {
		return $this->in_write(
			static function () use ( $work ): mixed {
				try {
					return $work();
				} catch ( \Throwable $error ) {
					return get_class( $error );
				}
			}
		);
	}

	/**
	 * A post with two blocks, and the content it stores.
	 */
	private function two_blocks(): int {
		return $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Kept title',
				'post_excerpt' => 'Kept excerpt',
				'post_content' => "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->",
			)
		);
	}

	public function test_a_malformed_segment_is_refused_with_an_exception_and_nothing_is_written(): void {
		$post   = $this->two_blocks();
		$stored = (string) get_post( $post )->post_content;
		$this->act_as_agent();
		$before = $this->row( $post );
		$length = strlen( $stored );
		$cases  = array(
			'not an array'           => array( 'kept' ),
			'no type'                => array(
				array(
					'start'  => 0,
					'length' => 1,
				),
			),
			'unknown type'           => array(
				array(
					'type' => 'raw',
					'text' => 'x',
				),
			),
			'kept with a text key'   => array(
				array(
					'type'   => 'kept',
					'start'  => 0,
					'length' => 1,
					'text'   => 'x',
				),
			),
			'kept start not an int'  => array(
				array(
					'type'   => 'kept',
					'start'  => '0',
					'length' => 1,
				),
			),
			'kept length missing'    => array(
				array(
					'type'  => 'kept',
					'start' => 0,
				),
			),
			'kept negative start'    => array(
				array(
					'type'   => 'kept',
					'start'  => -1,
					'length' => 1,
				),
			),
			'kept negative length'   => array(
				array(
					'type'   => 'kept',
					'start'  => 0,
					'length' => -1,
				),
			),
			'kept past the end'      => array(
				array(
					'type'   => 'kept',
					'start'  => 0,
					'length' => $length + 1,
				),
			),
			'overlapping kept'       => array(
				array(
					'type'   => 'kept',
					'start'  => 0,
					'length' => 10,
				),
				array(
					'type'   => 'kept',
					'start'  => 5,
					'length' => 10,
				),
			),
			'descending kept'        => array(
				array(
					'type'   => 'kept',
					'start'  => 20,
					'length' => 5,
				),
				array(
					'type'   => 'kept',
					'start'  => 0,
					'length' => 5,
				),
			),
			'sent text not a string' => array(
				array(
					'type' => 'sent',
					'text' => 5,
				),
			),
			'sent with a start key'  => array(
				array(
					'type'  => 'sent',
					'text'  => 'x',
					'start' => 0,
				),
			),
		);

		foreach ( $cases as $case => $segments ) {
			$this->assertSame(
				'InvalidArgumentException',
				$this->attempt( fn() => PostWriter::assemble( $post, $stored, $segments ) ),
				$case
			);
		}

		$this->assertSame( $before, $this->row( $post ), 'Nothing is written by a refused segment list.' );
	}

	public function test_kept_bytes_are_copied_verbatim_and_never_filtered(): void {
		$stored = '<!-- wp:html -->' . "\n" . '<script>var x = 1;</script>' . "\n" . '<!-- /wp:html -->';
		$post   = $this->two_blocks();
		$this->act_as_agent();

		$content = $this->attempt(
			fn() => PostWriter::assemble(
				$post,
				$stored,
				array(
					array(
						'type'   => 'kept',
						'start'  => 0,
						'length' => strlen( $stored ),
					),
				)
			)
		);

		$this->assertSame( $stored, $content );
	}

	public function test_adjacent_sent_segments_are_filtered_as_one_run(): void {
		$post = $this->two_blocks();
		$this->act_as_agent();
		$segments = array(
			array(
				'type' => 'sent',
				'text' => '<scr',
			),
			array(
				'type' => 'sent',
				'text' => 'ipt>alert(1)</script>',
			),
		);

		$result    = $this->attempt( fn() => PostWriter::assemble( $post, '', $segments ) );
		$piecewise = $this->attempt(
			fn() => PostWriter::filtered_content( $post, '<scr' ) . PostWriter::filtered_content( $post, 'ipt>alert(1)</script>' )
		);

		$whole = $this->attempt( fn() => PostWriter::filtered_content( $post, '<script>alert(1)</script>' ) );
		$this->assertSame( $whole, $result );
		$this->assertStringNotContainsString( '<script', (string) $result );
		$this->assertNotSame( $piecewise, $result, 'Filtering the pieces apart would give a different result.' );
	}

	public function test_assemble_report_says_whether_a_sent_run_was_changed(): void {
		$post = $this->two_blocks();
		$this->act_as_agent();

		$clean = $this->attempt(
			fn() => PostWriter::assemble_report(
				$post,
				'',
				array(
					array(
						'type' => 'sent',
						'text' => '<p>Plain</p>',
					),
				)
			)
		);
		$dirty = $this->attempt(
			fn() => PostWriter::assemble_report(
				$post,
				'',
				array(
					array(
						'type' => 'sent',
						'text' => '<p>x<script>1</script></p>',
					),
				)
			)
		);

		$this->assertFalse( $clean['altered'] );
		$this->assertTrue( $dirty['altered'] );
		$this->assertStringNotContainsString( '<script', $dirty['content'] );
	}

	public function test_the_pure_helpers_refuse_to_run_outside_a_write_call(): void {
		$post = $this->two_blocks();

		$this->expectException( \LogicException::class );
		PostWriter::assemble( $post, '', array() );
	}

	public function test_filtered_content_outside_a_write_call_is_refused(): void {
		$post = $this->two_blocks();

		$this->expectException( \LogicException::class );
		PostWriter::filtered_content( $post, '<p>x</p>' );
	}

	public function test_splice_outside_a_write_is_refused_and_nothing_is_written(): void {
		$post   = $this->two_blocks();
		$before = $this->row( $post );

		try {
			PostWriter::splice( $post, hash( 'sha256', (string) $before['post_content'] ), array() );
			$this->fail( 'splice must refuse to run outside a write.' );
		} catch ( \LogicException $error ) {
			unset( $error );
		}

		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_stored_content_that_changed_is_refused_with_nothing_written(): void {
		$post   = $this->two_blocks();
		$stored = (string) get_post( $post )->post_content;
		$this->act_as_agent();
		$before   = $this->row( $post );
		$segments = array(
			array(
				'type'   => 'kept',
				'start'  => 0,
				'length' => strlen( $stored ),
			),
		);

		$result = $this->in_write(
			static fn() => PostWriter::splice( $post, hash( 'sha256', 'something else' ), $segments )
		);

		$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_splice_puts_back_the_title_excerpt_and_filtered_content_byte_for_byte(): void {
		$post   = $this->two_blocks();
		$stored = (string) get_post( $post )->post_content;
		$this->act_as_agent();
		$before          = $this->row( $post );
		$filtered_before = (string) get_post( $post )->post_content_filtered;
		$segments        = array(
			array(
				'type'   => 'kept',
				'start'  => 0,
				'length' => 64,
			),
			array(
				'type' => 'sent',
				'text' => "\n\n<!-- wp:paragraph -->\n<p>Changed</p>\n<!-- /wp:paragraph -->",
			),
		);

		$result = $this->in_write(
			static fn() => PostWriter::splice( $post, hash( 'sha256', $stored ), $segments )
		);

		$this->assertSame( $post, $result );
		$after = $this->row( $post );
		foreach ( array( 'post_title', 'post_excerpt', 'post_status', 'post_author' ) as $field ) {
			$this->assertSame( $before[ $field ], $after[ $field ], "$field must be byte-identical after a splice." );
		}
		$this->assertSame( $filtered_before, (string) get_post( $post )->post_content_filtered );
		$this->assertSame( substr( $stored, 0, 64 ) . "\n\n<!-- wp:paragraph -->\n<p>Changed</p>\n<!-- /wp:paragraph -->", $after['post_content'] );
	}

	public function test_a_splice_filters_the_save_of_another_post_made_from_a_save_listener(): void {
		$post   = $this->two_blocks();
		$other  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Other</p>',
			)
		);
		$stored = (string) get_post( $post )->post_content;
		$this->act_as_agent();
		$segments = array(
			array(
				'type'   => 'kept',
				'start'  => 0,
				'length' => strlen( $stored ),
			),
		);
		$done     = false;
		$listener = static function ( int $saved ) use ( $post, $other, &$done ): void {
			if ( $saved !== $post || $done ) {
				return;
			}
			$done = true;
			wp_update_post(
				array(
					'ID'           => $other,
					'post_content' => '<p>Other</p><script>alert(1)</script>',
				)
			);
		};

		// The save filters are not present for this user in the test, so the splice must put them in place for its whole save.
		foreach ( self::KSES_PAIRS as $pair ) {
			remove_filter( $pair[0], $pair[1] );
		}
		add_action( 'save_post', $listener );
		try {
			$this->in_write( static fn() => PostWriter::splice( $post, hash( 'sha256', $stored ), $segments ) );
		} finally {
			remove_action( 'save_post', $listener );
			foreach ( self::KSES_PAIRS as $pair ) {
				add_filter( $pair[0], $pair[1] );
			}
		}

		$this->assertTrue( $done );
		$this->assertStringNotContainsString( '<script', (string) get_post( $other )->post_content );
	}

	public function test_a_nested_update_of_the_same_post_during_a_splice_does_not_get_the_splice_values_again(): void {
		$post   = $this->two_blocks();
		$stored = (string) get_post( $post )->post_content;
		$this->act_as_agent();
		$segments = array(
			array(
				'type'   => 'kept',
				'start'  => 0,
				'length' => strlen( $stored ),
			),
		);
		$done     = false;
		$listener = static function ( int $saved ) use ( $post, &$done ): void {
			if ( $saved !== $post || $done ) {
				return;
			}
			$done = true;
			PostWriter::update( $post, array( 'post_title' => 'Nested title' ) );
		};
		add_action( 'save_post', $listener );

		try {
			$this->in_write( static fn() => PostWriter::splice( $post, hash( 'sha256', $stored ), $segments ) );
		} finally {
			remove_action( 'save_post', $listener );
		}

		$this->assertTrue( $done );
		$row = $this->row( $post );
		$this->assertSame( 'Nested title', $row['post_title'], 'The nested update sets its own title.' );
		$this->assertSame( 'Kept excerpt', $row['post_excerpt'] );
		$this->assertSame( $stored, $row['post_content'] );
	}
}
