<?php
/**
 * Shared fixtures for the media tools' tests: real images and plain attachments, created as the fixture administrator
 * and removed after each test.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\Pipeline;

/**
 * Base for the media tests. Every attachment a test creates is deleted with its files in tear_down, so the uploads
 * directory is left as it was found.
 */
abstract class MediaToolTestCase extends ReadToolTestCase {

	/**
	 * Refusal text the pipeline gives for every object-level miss.
	 */
	protected const NOT_FOUND = 'Not found, or this agent may not access it.';

	/**
	 * Attachment IDs created by the current test.
	 *
	 * @var int[]
	 */
	private array $created = array();

	/**
	 * Deletes the attachments (and their files) this test created, before the database rolls back.
	 */
	protected function tearDown(): void {
		foreach ( $this->created as $id ) {
			if ( null !== get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
		}
		$this->created = array();

		parent::tearDown();
	}

	/**
	 * Calls a tool as the current user, and asserts after each call that the pipeline context has been cleared.
	 *
	 * @param string               $tool Tool name.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	protected function tool_result( string $tool, array $args = array() ): array {
		$result = parent::tool_result( $tool, $args );
		$this->assertFalse( Pipeline::is_running(), "The pipeline context must be cleared after $tool." );

		return $result;
	}

	/**
	 * Asserts that a tool's structured output validates against the ability's output schema. The pipeline does not
	 * check outputs, so the test does.
	 *
	 * @param array<string, mixed> $output  Structured output.
	 * @param string               $ability Ability name, e.g. steadhand/list-media.
	 */
	protected function assert_matches_output_schema( array $output, string $ability ): void {
		$valid = rest_validate_value_from_schema( $output, wp_get_ability( $ability )->get_output_schema(), 'output' );
		$this->assertNotInstanceOf( \WP_Error::class, $valid, "$ability output must match its output schema." );
	}

	/**
	 * Records an attachment for cleanup and applies any field overrides.
	 *
	 * @param int                  $id     Attachment ID.
	 * @param array<string, mixed> $fields Post fields to set after creation.
	 */
	private function track( int $id, array $fields = array() ): int {
		$this->created[] = $id;
		if ( array() !== $fields ) {
			wp_update_post( array_merge( array( 'ID' => $id ), $fields ) );
		}

		return $id;
	}

	/**
	 * A real JPEG (canola.jpg) uploaded through the test factory, so WordPress generates its metadata and sizes.
	 *
	 * @param int                  $parent_id Parent post ID, or 0.
	 * @param array<string, mixed> $fields Post fields to set after creation.
	 */
	protected function image( int $parent_id = 0, array $fields = array() ): int {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $parent_id );
		$this->assertIsInt( $id, 'The image fixture must upload.' );

		return $this->track( (int) $id, $fields );
	}

	/**
	 * An attachment row with a MIME type and no file on disk.
	 *
	 * @param string               $mime   MIME type.
	 * @param int                  $parent_id Parent post ID, or 0.
	 * @param array<string, mixed> $fields Post fields to set after creation.
	 */
	protected function plain( string $mime, int $parent_id = 0, array $fields = array() ): int {
		return $this->plain_by( $this->admin_id(), $mime, $parent_id, $fields );
	}

	/**
	 * An attachment row written by the given author.
	 *
	 * @param int                  $author Author user ID.
	 * @param string               $mime   MIME type.
	 * @param int                  $parent_id Parent post ID, or 0.
	 * @param array<string, mixed> $fields Post fields to set after creation.
	 */
	protected function plain_by( int $author, string $mime, int $parent_id = 0, array $fields = array() ): int {
		$id = wp_insert_attachment(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime,
				'post_title'     => 'Plain file',
				'post_author'    => $author,
			),
			false,
			$parent_id
		);
		$this->assertGreaterThan( 0, $id, 'The attachment fixture must be created.' );

		return $this->track( (int) $id, $fields );
	}

	/**
	 * A plain attachment with a fixed date, in site and GMT time.
	 *
	 * @param string $mime MIME type.
	 * @param string $date Local date and time, Y-m-d H:i:s.
	 * @param string $title Title.
	 */
	protected function dated( string $mime, string $date, string $title = 'Plain file' ): int {
		return $this->plain(
			$mime,
			0,
			array(
				'post_title'        => $title,
				'post_date'         => $date,
				'post_date_gmt'     => get_gmt_from_date( $date ),
				'post_modified'     => $date,
				'post_modified_gmt' => get_gmt_from_date( $date ),
			)
		);
	}

	/**
	 * Gives an image metadata that core never writes itself: EXIF and IPTC fields with fake GPS and camera values, an
	 * absolute server path, and an alt text and caption. Used to prove none of it is returned.
	 *
	 * @param int $id Image attachment ID.
	 */
	protected function with_sensitive_metadata( int $id ): void {
		$meta = wp_get_attachment_metadata( $id );
		$this->assertIsArray( $meta, 'The image must have metadata.' );

		$meta['file']       = '/srv/steadhand-secret/hidden/canola.jpg';
		$meta['image_meta'] = array(
			'camera'    => 'FAKECAM-9000',
			'latitude'  => '51.5007',
			'longitude' => '-0.1246',
			'copyright' => 'FAKE-COPYRIGHT-HOLDER',
			'credit'    => 'FAKE-CREDIT',
			'artist'    => 'FAKE-ARTIST',
		);
		wp_update_attachment_metadata( $id, $meta );
		update_post_meta( $id, '_wp_attachment_image_alt', 'A field of canola' );
	}

	/**
	 * Asserts that a decoded tool output holds none of the forbidden data: no key of a server path, metadata, EXIF or
	 * GPS field, and no user email or login, and that the encoded text holds no server path or fake EXIF value.
	 *
	 * @param array<string, mixed> $output  Structured output.
	 * @param string               $context Where, for the failure message.
	 */
	protected function assert_no_forbidden_data( array $output, string $context ): void {
		$keys = $this->keys_at_any_depth( $output );
		foreach ( array( 'file', 'image_meta', 'latitude', 'longitude', 'camera', 'copyright', 'credit', 'artist', '_wp_attached_file', '_wp_attachment_metadata', 'email', 'login', 'user_email', 'user_login' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $keys, "$context: the key $forbidden must never be returned." );
		}

		$encoded = (string) wp_json_encode( $output );
		$admin   = get_userdata( $this->admin_id() );
		$needles = array(
			'FAKECAM-9000',
			'51.5007',
			'-0.1246',
			'FAKE-COPYRIGHT-HOLDER',
			'FAKE-ARTIST',
			'/srv/steadhand-secret',
			untrailingslashit( ABSPATH ),
			(string) $admin->user_email,
			(string) $admin->user_login,
		);
		foreach ( $needles as $needle ) {
			$this->assertStringNotContainsString( $needle, $encoded, "$context: the value $needle must never be returned." );
		}
	}
}
