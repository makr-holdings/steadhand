<?php
/**
 * The steadhand/get-media ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §5 (get-media), §14.1 and the media rules of the M1 brief.
 */
final class GetMediaTest extends MediaToolTestCase {

	private const TOOL_NAME = 'steadhand-get-media';

	private const DETAIL_KEYS = array( 'id', 'title', 'slug', 'mime_type', 'media_type', 'url', 'alt', 'caption', 'date_gmt', 'modified_gmt', 'author', 'parent', 'width', 'height', 'filesize', 'duration', 'description', 'truncated', 'sizes' );

	public function test_an_image_returns_dimensions_sizes_with_urls_alt_and_caption(): void {
		$this->act_as_agent();
		$id = $this->image( 0, array( 'post_excerpt' => 'Canola & friends' ) );
		update_post_meta( $id, '_wp_attachment_image_alt', 'A field of canola' );
		$meta    = wp_get_attachment_metadata( $id );
		$uploads = wp_get_upload_dir();

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertSame( self::DETAIL_KEYS, array_keys( $output ) );
		$this->assert_matches_output_schema( $output, 'steadhand/get-media' );
		$this->assertSame( 'image', $output['media_type'] );
		$this->assertSame( 'image/jpeg', $output['mime_type'] );
		$this->assertSame( $meta['width'], $output['width'] );
		$this->assertSame( $meta['height'], $output['height'] );
		$this->assertSame( 'A field of canola', $output['alt'] );
		$this->assertSame( 'Canola & friends', $output['caption'] );
		$this->assertSame( wp_get_attachment_url( $id ), $output['url'] );
		$this->assertStringStartsWith( $uploads['baseurl'], $output['url'] );

		$this->assertNotEmpty( $output['sizes'] );
		$this->assertLessThanOrEqual( 30, count( $output['sizes'] ) );
		foreach ( $output['sizes'] as $size ) {
			$this->assertSame( array( 'name', 'url', 'width', 'height', 'mime_type' ), array_keys( $size ) );
			$this->assertStringStartsWith( $uploads['baseurl'], $size['url'] );
			$this->assertIsInt( $size['width'] );
		}
		$this->assertContains( 'thumbnail', wp_list_pluck( $output['sizes'], 'name' ) );
		$this->assertFalse( $output['truncated'] );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_a_pdf_has_no_dimensions_and_no_sizes(): void {
		$this->act_as_agent();
		$id = $this->plain( 'application/pdf' );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assert_matches_output_schema( $output, 'steadhand/get-media' );
		$this->assertSame( 'application', $output['media_type'] );
		$this->assertNull( $output['width'] );
		$this->assertNull( $output['height'] );
		$this->assertNull( $output['duration'] );
		$this->assertNull( $output['filesize'] );
		$this->assertSame( array(), $output['sizes'] );
	}

	public function test_audio_duration_comes_from_the_length_in_its_metadata(): void {
		$this->act_as_agent();
		$id = $this->plain( 'audio/mpeg' );
		wp_update_attachment_metadata( $id, array( 'length' => 212 ) );

		$this->assertSame( 212, $this->output( self::TOOL_NAME, array( 'id' => $id ) )['duration'] );
	}

	public function test_filesize_comes_from_the_metadata_when_it_is_stored(): void {
		$this->act_as_agent();
		$id               = $this->image();
		$meta             = wp_get_attachment_metadata( $id );
		$meta['filesize'] = 12345;
		wp_update_attachment_metadata( $id, $meta );

		$this->assertSame( 12345, $this->output( self::TOOL_NAME, array( 'id' => $id ) )['filesize'] );
	}

	public function test_a_file_missing_on_disk_does_not_fail_the_call(): void {
		$this->act_as_agent();
		$id = $this->image();
		wp_delete_file( get_attached_file( $id ) );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertSame( 'image', $output['media_type'] );
		$this->assertIsInt( $output['width'] );
	}

	public function test_a_private_parent_does_not_hide_its_attachment(): void {
		$this->act_as_agent();
		$parent = $this->admin_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
			)
		);
		$id     = $this->image( $parent );

		$this->assertSame( $id, $this->output( self::TOOL_NAME, array( 'id' => $id ) )['id'] );
	}

	public function test_the_description_is_plain_text_capped_at_5000_bytes_and_flagged(): void {
		$this->act_as_agent();
		$long = '<!-- wp:paragraph --><p>' . str_repeat( "\xc3\xa9t\xc3\xa9 & ", 2000 ) . '</p><!-- /wp:paragraph -->';
		$id   = $this->plain( 'text/plain', 0, array( 'post_content' => $long ) );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertTrue( $output['truncated'] );
		$this->assertLessThanOrEqual( 5000, strlen( $output['description'] ) );
		$this->assertTrue( mb_check_encoding( $output['description'], 'UTF-8' ), 'The cut must not split a character.' );
		$this->assertStringNotContainsString( '<!--', $output['description'] );
		$this->assertStringNotContainsString( '<p>', $output['description'] );
		$this->assertStringContainsString( '&', $output['description'], 'Entities are decoded to plain text.' );
	}

	public function test_a_short_description_is_returned_whole_and_not_flagged(): void {
		$this->act_as_agent();
		$id = $this->plain( 'text/plain', 0, array( 'post_content' => '<p>Tom &amp; Jerry</p>' ) );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertSame( 'Tom & Jerry', $output['description'] );
		$this->assertFalse( $output['truncated'] );
	}

	public function test_a_missing_id_a_regular_post_and_a_trashed_attachment_get_one_refusal(): void {
		$this->act_as_agent();
		$regular = $this->admin_post( array( 'post_status' => 'publish' ) );
		$trashed = $this->image();
		wp_trash_post( $trashed );

		$texts = array(
			'missing' => $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => 999999999 ) ) ),
			'post'    => $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $regular ) ) ),
			'trashed' => $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $trashed ) ) ),
		);

		$this->assertSame( array( self::NOT_FOUND ), array_values( array_unique( $texts ) ), wp_json_encode( $texts ) );
	}

	public function test_an_agent_without_steadhand_read_is_refused(): void {
		$id = $this->image();
		$this->act_as_agent( array( Capabilities::READ => false ) );

		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'id' => $id ) )['isError'] );
	}

	public function test_schema_refuses_a_zero_id_and_unknown_properties(): void {
		$this->act_as_agent();

		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'id' => 0 ) )['isError'] );
		$this->assertTrue(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'   => 1,
					'file' => '/etc/passwd',
				)
			)['isError']
		);
	}

	public function test_the_output_never_holds_server_paths_metadata_exif_gps_or_user_details(): void {
		$this->act_as_agent();
		$id = $this->image( 0, array( 'post_excerpt' => 'Canola field' ) );
		$this->with_sensitive_metadata( $id );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertSame( 'Canola field', $output['caption'] );
		$this->assertSame( 'A field of canola', $output['alt'] );
		$this->assert_no_forbidden_data( $output, 'get-media' );
	}
}
