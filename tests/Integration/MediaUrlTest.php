<?php
/**
 * URL hygiene for media output (FIX 3). A URL is returned only for a file the upload directory holds, never a GUID or
 * a server path, and size URLs are built from the file name only.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Each case stores an odd attachment and reads it through get-media and list-media.
 */
final class MediaUrlTest extends MediaToolTestCase {

	private const GET_TOOL  = 'steadhand-get-media';
	private const LIST_TOOL = 'steadhand-list-media';

	/**
	 * Reads an attachment through both tools, asserts both succeed, and returns the get-media output.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>
	 */
	private function detail( int $id ): array {
		$detail = $this->output( self::GET_TOOL, array( 'id' => $id ) );
		$this->assert_matches_output_schema( $detail, 'steadhand/get-media' );

		$row = array_column( $this->output( self::LIST_TOOL, array( 'per_page' => 50 ) )['items'], null, 'id' )[ $id ] ?? null;
		$this->assertIsArray( $row, 'The attachment must be listed.' );
		$this->assertSame( $detail['url'], $row['url'], 'list-media and get-media must give the same URL.' );

		return $detail;
	}

	public function test_a_normal_upload_returns_its_url_and_its_size_urls(): void {
		$this->act_as_agent();
		$id      = $this->image();
		$uploads = wp_get_upload_dir();

		$detail = $this->detail( $id );

		$this->assertSame( wp_get_attachment_url( $id ), $detail['url'] );
		$this->assertStringStartsWith( $uploads['baseurl'], $detail['url'] );
		$this->assertNotEmpty( $detail['sizes'] );
		foreach ( $detail['sizes'] as $size ) {
			$this->assertStringStartsWith( trailingslashit( dirname( $detail['url'] ) ), $size['url'] );
		}
	}

	public function test_an_attachment_with_no_attached_file_gets_an_empty_url_even_when_its_guid_is_set(): void {
		$this->act_as_agent();
		$id = $this->plain( 'application/pdf' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'guid' => 'http://internal-host.example/private/file.pdf' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Sets a GUID that the API will not store; the test needs exactly this row.
		clean_post_cache( $id );
		$this->assertSame( 'http://internal-host.example/private/file.pdf', wp_get_attachment_url( $id ), 'Control: core falls back to the GUID here.' );

		$detail = $this->detail( $id );

		$this->assertSame( '', $detail['url'] );
		$this->assertStringNotContainsString( 'internal-host', (string) wp_json_encode( $detail ) );
	}

	public function test_an_image_with_no_attached_file_gets_an_empty_url_and_no_sizes(): void {
		$this->act_as_agent();
		$id = $this->image();
		delete_post_meta( $id, '_wp_attached_file' );

		$detail = $this->detail( $id );

		$this->assertSame( '', $detail['url'] );
		$this->assertSame( array(), $detail['sizes'] );
	}

	/**
	 * Absolute server paths in _wp_attached_file, in each form core would embed in a URL.
	 *
	 * @return array<string, array{string}>
	 */
	public static function absolute_paths(): array {
		return array(
			'posix path'    => array( '/srv/steadhand-secret/hidden/canola.jpg' ),
			'windows drive' => array( 'C:\\inetpub\\steadhand-secret\\canola.jpg' ),
			'windows slash' => array( 'D:/steadhand-secret/canola.jpg' ),
			'unc share'     => array( '\\\\fileserver\\steadhand-secret\\canola.jpg' ),
		);
	}

	/**
	 * Each absolute path must give an empty URL and no sizes.
	 *
	 * @dataProvider absolute_paths
	 *
	 * @param string $path Stored value of _wp_attached_file.
	 */
	public function test_an_attached_file_that_is_an_absolute_path_gets_an_empty_url_and_no_sizes( string $path ): void {
		$this->act_as_agent();
		$id = $this->image();
		update_post_meta( $id, '_wp_attached_file', $path );

		$detail = $this->detail( $id );

		$this->assertSame( '', $detail['url'], $path );
		$this->assertSame( array(), $detail['sizes'], $path );
		$this->assertStringNotContainsString( 'steadhand-secret', (string) wp_json_encode( $detail ) );
	}

	public function test_size_urls_are_built_from_the_file_name_of_each_size(): void {
		$this->act_as_agent();
		$id            = $this->image();
		$original_meta = wp_get_attachment_metadata( $id );
		$meta          = $original_meta;
		$base          = trailingslashit( dirname( wp_get_attachment_url( $id ) ) );

		$meta['sizes']['traversal'] = array(
			'file'      => '../../etc/passwd',
			'width'     => 10,
			'height'    => 10,
			'mime-type' => 'image/png',
		);
		$meta['sizes']['foreign']   = array(
			'file'      => 'http://evil.example/x.png',
			'width'     => 10,
			'height'    => 10,
			'mime-type' => 'image/png',
		);
		wp_update_attachment_metadata( $id, $meta );

		$urls = array_column( $this->detail( $id )['sizes'], 'url', 'name' );
		wp_update_attachment_metadata( $id, $original_meta );

		$this->assertSame( $base . 'passwd', $urls['traversal'] );
		$this->assertSame( $base . 'x.png', $urls['foreign'] );
	}
}
