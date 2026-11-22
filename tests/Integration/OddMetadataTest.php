<?php
/**
 * One odd attachment must never break a media call (FIX 2). Metadata that is malformed, out of range or oversized is
 * clamped or dropped, and both media tools still return output that matches the ability's output schema.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Each case stores odd values in an attachment's metadata or row, then calls get-media and list-media as an agent and
 * validates both results against their output schemas.
 */
final class OddMetadataTest extends MediaToolTestCase {

	private const GET_TOOL  = 'steadhand-get-media';
	private const LIST_TOOL = 'steadhand-list-media';

	/**
	 * Calls get-media and list-media for one attachment, asserts both succeed and validate, and returns the get-media
	 * output and the list row for the attachment.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $label Case name, for failure messages.
	 * @return array{detail: array<string, mixed>, row: array<string, mixed>}
	 */
	private function both_tools( int $id, string $label ): array {
		$detail = $this->output( self::GET_TOOL, array( 'id' => $id ) );
		$this->assert_matches_output_schema( $detail, 'steadhand/get-media' );

		$list = $this->output( self::LIST_TOOL, array( 'per_page' => 50 ) );
		$this->assert_matches_output_schema( $list, 'steadhand/list-media' );

		$rows = array_column( $list['items'], null, 'id' );
		$this->assertArrayHasKey( $id, $rows, "$label: the row must be listed." );

		return array(
			'detail' => $detail,
			'row'    => $rows[ $id ],
		);
	}

	/**
	 * Replaces one key of an image's metadata.
	 *
	 * @param int    $id    Attachment ID.
	 * @param string $key   Metadata key.
	 * @param mixed  $value New value.
	 */
	private function set_meta( int $id, string $key, mixed $value ): void {
		$meta         = wp_get_attachment_metadata( $id );
		$meta[ $key ] = $value;
		wp_update_attachment_metadata( $id, $meta );
	}

	public function test_a_duration_that_is_not_a_finite_non_negative_number_is_null_and_the_call_still_succeeds(): void {
		$this->act_as_agent();
		$cases = array(
			'NAN'      => NAN,
			'INF'      => INF,
			'-INF'     => -INF,
			'negative' => -5,
			'a string' => 'abc',
		);

		foreach ( $cases as $case => $length ) {
			$id = $this->plain( 'audio/mpeg' );
			wp_update_attachment_metadata( $id, array( 'length' => $length ) );

			$both = $this->both_tools( $id, "length $case" );

			$this->assertNull( $both['detail']['duration'], "length $case" );
			$this->assertNull( $both['row']['duration'], "length $case" );
		}
	}

	public function test_a_finite_non_negative_duration_is_returned(): void {
		$this->act_as_agent();
		$id = $this->plain( 'audio/mpeg' );
		wp_update_attachment_metadata( $id, array( 'length' => 12.5 ) );

		$this->assertSame( 12.5, $this->both_tools( $id, 'a valid length' )['detail']['duration'] );
	}

	public function test_a_size_with_an_overlong_mime_type_or_name_is_skipped_and_the_normal_size_stays(): void {
		$this->act_as_agent();
		$id            = $this->image();
		$meta          = wp_get_attachment_metadata( $id );
		$normal        = $meta['sizes']['thumbnail'];
		$meta['sizes'] = array(
			'thumbnail'            => $normal,
			'long-mime'            => array(
				'file'      => 'long-mime.jpg',
				'width'     => 10,
				'height'    => 10,
				'mime-type' => 'image/' . str_repeat( 'x', 300 ),
			),
			str_repeat( 'n', 200 ) => array(
				'file'      => 'long-name.jpg',
				'width'     => 10,
				'height'    => 10,
				'mime-type' => 'image/jpeg',
			),
		);
		wp_update_attachment_metadata( $id, $meta );

		$sizes = $this->both_tools( $id, 'overlong size fields' )['detail']['sizes'];

		$this->assertSame( array( 'thumbnail' ), wp_list_pluck( $sizes, 'name' ) );
	}

	public function test_a_size_without_a_usable_file_name_is_skipped(): void {
		$this->act_as_agent();
		$id                          = $this->image();
		$original                    = wp_get_attachment_metadata( $id );
		$meta                        = $original;
		$meta['sizes']['no-file']    = array(
			'width'     => 10,
			'height'    => 10,
			'mime-type' => 'image/jpeg',
		);
		$meta['sizes']['empty-file'] = array(
			'file'      => '',
			'width'     => 10,
			'height'    => 10,
			'mime-type' => 'image/jpeg',
		);
		$meta['sizes']['array-file'] = array(
			'file'      => array( 'x' ),
			'width'     => 10,
			'height'    => 10,
			'mime-type' => 'image/jpeg',
		);
		$meta['sizes']['array-mime'] = array(
			'file'      => 'array-mime.jpg',
			'width'     => 10,
			'height'    => 10,
			'mime-type' => array( 'image' ),
		);
		wp_update_attachment_metadata( $id, $meta );

		$both = $this->both_tools( $id, 'unusable sizes' );
		// Core cannot delete an attachment whose sizes have no usable file, so the original metadata goes back first.
		wp_update_attachment_metadata( $id, $original );
		$names = wp_list_pluck( $both['detail']['sizes'], 'name' );

		$this->assertContains( 'thumbnail', $names );
		$this->assertNotContains( 'no-file', $names );
		$this->assertNotContains( 'empty-file', $names );
		$this->assertNotContains( 'array-file', $names );
		$this->assertContains( 'array-mime', $names, 'A size with a non-string mime type is kept, with an empty mime type.' );
	}

	public function test_an_overlong_mime_type_is_cut_and_its_media_type_is_empty(): void {
		$this->act_as_agent();
		$id = $this->plain( 'image/png' );
		// The column is varchar(100), so a longer value cannot be stored. This seeds the post cache with a row that
		// holds one, as a writer that stores it anyway would leave it, and the reader must still hold the schema.
		$row                 = get_post( $id );
		$row->post_mime_type = 'image/' . str_repeat( 'a', 145 );
		wp_cache_set( $id, $row, 'posts' );

		$both = $this->both_tools( $id, 'a 151-character MIME type' );

		$this->assertSame( 100, strlen( $both['detail']['mime_type'] ) );
		$this->assertSame( '', $both['detail']['media_type'] );
		$this->assertSame( $both['detail']['mime_type'], $both['row']['mime_type'] );
	}

	public function test_a_malformed_mime_type_gets_an_empty_media_type_and_the_call_still_succeeds(): void {
		$this->act_as_agent();
		$id = $this->plain( 'image/' );

		$both = $this->both_tools( $id, 'a MIME type with no subtype' );

		$this->assertSame( '', $both['detail']['media_type'] );
		$this->assertSame( 'image/', $both['detail']['mime_type'] );
	}

	public function test_alt_caption_and_title_are_plain_text_cut_to_one_thousand_bytes(): void {
		$this->act_as_agent();
		$long = str_repeat( "\u{00e9}", 50000 ); // 100 KB of two-byte characters.
		$id   = $this->plain(
			'image/png',
			0,
			array(
				'post_title'   => $long,
				'post_excerpt' => $long,
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', $long );

		$both = $this->both_tools( $id, 'long text fields' );

		foreach ( array( 'title', 'alt', 'caption' ) as $field ) {
			$this->assertLessThanOrEqual( 1000, strlen( $both['detail'][ $field ] ), $field );
			$this->assertSame( 1, preg_match( '//u', $both['detail'][ $field ] ), "$field must stay valid UTF-8." );
			$this->assertSame( $both['detail'][ $field ], $both['row'][ $field ], $field );
		}
		$this->assertSame( 1000, strlen( $both['detail']['alt'] ) );
	}

	public function test_a_non_integer_or_out_of_range_dimension_is_null_and_the_call_still_succeeds(): void {
		$this->act_as_agent();
		$id = $this->image();

		$cases = array(
			'a string'      => '100',
			'a float'       => 100.5,
			'negative'      => -5,
			'zero'          => 0,
			'PHP_INT_MAX'   => PHP_INT_MAX,
			'above 1000000' => 1000001,
		);

		foreach ( $cases as $case => $value ) {
			$this->set_meta( $id, 'width', $value );
			$this->set_meta( $id, 'height', $value );

			$both = $this->both_tools( $id, "dimension $case" );

			$this->assertNull( $both['detail']['width'], "width $case" );
			$this->assertNull( $both['detail']['height'], "height $case" );
			$this->assertNull( $both['row']['width'], "list width $case" );
		}
	}

	public function test_a_positive_dimension_within_the_bound_is_returned(): void {
		$this->act_as_agent();
		$id = $this->image();
		$this->set_meta( $id, 'width', 1000000 );
		$this->set_meta( $id, 'height', 1 );

		$both = $this->both_tools( $id, 'the largest allowed dimension' );

		$this->assertSame( 1000000, $both['detail']['width'] );
		$this->assertSame( 1, $both['detail']['height'] );
	}

	public function test_a_non_positive_or_non_integer_filesize_is_null(): void {
		$this->act_as_agent();
		$id = $this->plain( 'application/pdf' );

		foreach ( array(
			'zero'     => 0,
			'negative' => -1,
			'a float'  => 1.5,
			'a string' => '12',
		) as $case => $value ) {
			wp_update_attachment_metadata( $id, array( 'filesize' => $value ) );

			$this->assertNull( $this->both_tools( $id, "filesize $case" )['detail']['filesize'], "filesize $case" );
		}
	}

	public function test_a_list_with_an_odd_row_and_a_good_row_returns_both(): void {
		$this->act_as_agent();
		$odd = $this->plain( 'audio/mpeg' );
		wp_update_attachment_metadata( $odd, array( 'length' => INF ) );
		$good = $this->image();

		$items = array_column( $this->output( self::LIST_TOOL, array( 'per_page' => 50 ) )['items'], null, 'id' );

		$this->assertArrayHasKey( $odd, $items );
		$this->assertArrayHasKey( $good, $items );
	}
}
