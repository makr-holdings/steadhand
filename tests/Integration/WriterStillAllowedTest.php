<?php
/**
 * The writers' last permission check: a $still_allowed that refuses, run between the authorization and the write, writes nothing.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\MediaWriter;
use Steadhand\Pipeline\TermWriter;

/**
 * Covers the $still_allowed parameter of TermWriter::set() and MediaWriter::set_featured_image(). The undo passes a callable that
 * re-runs its own checks, so a refusal there must stop the write in both writers.
 */
final class WriterStillAllowedTest extends PostWriteTestCase {

	public function test_terms_writer_refuses_when_still_allowed_says_no_and_writes_nothing(): void {
		$this->act_as_agent();
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );
		$tag  = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Refused',
			)
		);

		$result = $this->in_write(
			static fn(): mixed => TermWriter::set( $post, 'post_tag', array( $tag ), array(), static fn(): bool => false )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( array(), array_map( 'intval', (array) wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) ) );
	}

	public function test_featured_image_writer_refuses_when_still_allowed_says_no_and_writes_nothing(): void {
		$this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );

		$result = $this->in_write(
			static fn(): mixed => MediaWriter::set_featured_image( $post, $image, 0, static fn(): bool => false )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( 0, MediaWriter::stored_thumbnail( $post ) );
	}
}
