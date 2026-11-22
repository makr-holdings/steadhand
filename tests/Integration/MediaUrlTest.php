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
	 * A GUID that names an internal host. It must never appear in any output of the media tools.
	 */
	private const INTERNAL_GUID = 'http://internal.corp.local/secret/x.mp4';

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

	/**
	 * The list-media output, as the tool returns it.
	 *
	 * @return array<string, mixed>
	 */
	private function list_output(): array {
		return $this->output( self::LIST_TOOL, array( 'per_page' => 50 ) );
	}

	/**
	 * Stores a GUID on an attachment, as a site could have it, and clears the cache so core reads the new value.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $guid GUID to store.
	 */
	private function set_guid( int $id, string $guid ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'guid' => $guid ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Sets a GUID that the API will not store; the test needs exactly this row.
		clean_post_cache( $id );
	}

	/**
	 * An attachment whose stored file is $file and whose GUID is $guid.
	 *
	 * @param string $mime MIME type.
	 * @param string $file Stored value of _wp_attached_file.
	 * @param string $guid GUID to store.
	 */
	private function attachment_with_file_and_guid( string $mime, string $file, string $guid ): int {
		$id = $this->plain( $mime );
		update_post_meta( $id, '_wp_attached_file', $file );
		$this->set_guid( $id, $guid );

		return $id;
	}

	public function test_a_normal_upload_returns_its_url_and_its_size_urls(): void {
		$this->act_as_agent();
		$id      = $this->image();
		$uploads = wp_get_upload_dir();

		$detail = $this->detail( $id );

		$this->assertSame( wp_get_attachment_url( $id ), $detail['url'] );
		$this->assertSame( get_the_guid( $id ), $detail['url'], 'A fresh upload\'s GUID is its file URL, so the GUID guard must keep it.' );
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

	public function test_a_stored_file_of_zero_gets_an_empty_url_even_when_its_guid_is_set(): void {
		$this->act_as_agent();
		$id = $this->attachment_with_file_and_guid( 'video/mp4', '0', self::INTERNAL_GUID );
		$this->assertSame( self::INTERNAL_GUID, wp_get_attachment_url( $id ), 'Control: core falls back to the GUID for "0".' );

		$detail = $this->detail( $id );

		$this->assertSame( '', $detail['url'] );
		$this->assertStringNotContainsString( 'internal.corp.local', (string) wp_json_encode( array( $detail, $this->list_output() ) ) );
	}

	public function test_a_stored_file_of_one_space_gets_an_empty_url_even_when_its_guid_is_set(): void {
		$this->act_as_agent();
		$id      = $this->attachment_with_file_and_guid( 'video/mp4', ' ', self::INTERNAL_GUID );
		$uploads = wp_get_upload_dir();
		$this->assertStringStartsWith( $uploads['baseurl'], wp_get_attachment_url( $id ), 'Control: core builds an uploads URL from " ", which is neither empty nor the GUID.' );

		$detail = $this->detail( $id );

		$this->assertSame( '', $detail['url'] );
		$this->assertStringNotContainsString( 'internal.corp.local', (string) wp_json_encode( array( $detail, $this->list_output() ) ) );
	}

	/**
	 * Isolates the GUID guard: the file is real, so the falsy-value rule does not apply, and a filter makes core's URL
	 * the GUID itself.
	 */
	public function test_a_real_file_whose_url_is_the_guid_and_not_the_file_is_refused(): void {
		$this->act_as_agent();
		$id = $this->image();
		$this->set_guid( $id, self::INTERNAL_GUID );
		$filter = static fn (): string => self::INTERNAL_GUID;
		add_filter( 'wp_get_attachment_url', $filter );

		try {
			$detail = $this->detail( $id );
		} finally {
			remove_filter( 'wp_get_attachment_url', $filter );
		}

		$this->assertSame( '', $detail['url'] );
		$this->assertSame( array(), $detail['sizes'] );
		$this->assertStringNotContainsString( 'internal.corp.local', (string) wp_json_encode( array( $detail, $this->list_output() ) ) );
	}

	/**
	 * Isolates the falsy-value rule: core is not consulted for "0", so a URL a filter supplies for it must not pass.
	 */
	public function test_a_falsy_stored_file_is_refused_even_when_a_filter_supplies_a_url(): void {
		$this->act_as_agent();
		$id     = $this->attachment_with_file_and_guid( 'video/mp4', '0', 'https://other.example/clip.mp4' );
		$filter = static fn (): string => self::INTERNAL_GUID;
		add_filter( 'wp_get_attachment_url', $filter );

		try {
			$detail = $this->detail( $id );
		} finally {
			remove_filter( 'wp_get_attachment_url', $filter );
		}

		$this->assertSame( '', $detail['url'] );
		$this->assertStringNotContainsString( 'internal.corp.local', (string) wp_json_encode( array( $detail, $this->list_output() ) ) );
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
