<?php
/**
 * The only place Steadhand creates an attachment or sets a featured image.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Creates image attachments from bytes the caller already checked, and sets or removes the featured image of a post (SPEC
 * §5, §14.1). Every media write in Steadhand goes through here, and only while Pipeline::is_writing() is true.
 *
 * The type, size and pixel rules are the ability's (UploadMedia). This class checks only what it needs to be safe on its
 * own: that it is called inside a write, the shape of its arguments, and that the image decodes. Core's sideload then
 * stores the file under an explicit allowlist of image types, so a file that is not one of those is refused by core too.
 */
final class MediaWriter {

	/**
	 * The extension-to-type map passed to core's sideload as its allowlist. The same four types UploadMedia accepts.
	 */
	private const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * The fields create_image() takes. Anything else is refused.
	 */
	private const FIELDS = array( 'title', 'alt', 'caption' );

	/**
	 * Stores image bytes as a new attachment in the media library, with its metadata and generated sizes.
	 *
	 * The bytes go to a temporary file, which core's sideload then moves into the uploads directory under a name it makes
	 * unique. The file must decode as an image, or nothing is stored. The author is the current user, the status is inherit,
	 * and the parent is $parent_id. On any failure after the file exists, the file and any attachment row made for it are
	 * removed, so nothing is left behind. The temporary file is removed in every case.
	 *
	 * @param string               $bytes     The image, already decoded and checked by the ability.
	 * @param string               $filename  The file name, already sanitized; its extension must match the bytes.
	 * @param array<string, mixed> $fields    Optional title, alt and caption (strings only).
	 * @param int                  $parent_id Parent post ID, or 0.
	 * @return int|\WP_Error The new attachment's ID, or a WP_Error. Steadhand_invalid_input when the image cannot be stored or
	 *                       decoded; steadhand_error when a step core needs fails.
	 * @throws \InvalidArgumentException When a field is not allowlisted or not a string, or an argument is out of range.
	 */
	public static function create_image( string $bytes, string $filename, array $fields, int $parent_id ): int|\WP_Error {
		self::assert_writing();
		self::assert_fields( $fields );

		if ( '' === $bytes || $parent_id < 0 ) {
			throw new \InvalidArgumentException( 'MediaWriter needs image bytes and a parent ID of 0 or more.' );
		}

		$name = sanitize_file_name( $filename );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'MediaWriter needs a file name.' );
		}

		self::load_admin_includes();

		// The temporary file keeps the file name's extension, because the image editor chooses its handler by it. wp_tempnam()
		// would leave the name without one.
		$tmp = trailingslashit( get_temp_dir() ) . 'steadhand-' . wp_generate_password( 16, false ) . '-' . $name;

		$file = '';
		$id   = 0;
		$done = false;

		try {
			if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file in the system temp directory, removed in finally.
				return self::storage_error();
			}

			// Core decodes the image when it makes the sizes, so an image that will not decode is refused now.
			if ( is_wp_error( wp_get_image_editor( $tmp ) ) ) {
				return new \WP_Error(
					'steadhand_invalid_input',
					__( 'The image data could not be read as an image, so nothing was stored.', 'steadhand' ),
					array( 'status' => 400 )
				);
			}

			$file_array = array(
				'name'     => $name,
				'type'     => '',
				'tmp_name' => $tmp,
				'error'    => 0,
				'size'     => strlen( $bytes ),
			);

			$stored = wp_handle_sideload(
				$file_array,
				array(
					'test_form' => false,
					'mimes'     => self::MIMES,
				)
			);
			if ( ! is_array( $stored ) || isset( $stored['error'] ) || empty( $stored['file'] ) || empty( $stored['type'] ) ) {
				return new \WP_Error(
					'steadhand_invalid_input',
					__( 'WordPress would not store this image, so nothing was stored.', 'steadhand' ),
					array( 'status' => 400 )
				);
			}

			$file = (string) $stored['file'];

			$title = isset( $fields['title'] ) ? (string) $fields['title'] : (string) pathinfo( $name, PATHINFO_FILENAME );

			$attachment = wp_insert_attachment(
				array(
					'post_title'     => $title,
					'post_excerpt'   => (string) ( $fields['caption'] ?? '' ),
					'post_content'   => '',
					'post_status'    => 'inherit',
					'post_mime_type' => (string) $stored['type'],
					'post_author'    => get_current_user_id(),
					'guid'           => (string) ( $stored['url'] ?? '' ),
				),
				$file,
				$parent_id,
				true
			);
			if ( is_wp_error( $attachment ) || 0 === (int) $attachment ) {
				return self::storage_error();
			}
			$id = (int) $attachment;

			$meta = wp_generate_attachment_metadata( $id, $file );
			if ( ! is_array( $meta ) || array() === $meta ) {
				return self::storage_error();
			}
			wp_update_attachment_metadata( $id, $meta );

			if ( isset( $fields['alt'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $fields['alt'] );
			}

			$done = true;
			return $id;
		} finally {
			wp_delete_file( $tmp );

			if ( ! $done ) {
				self::remove_partial( $id, $file );
			}
		}
	}

	/**
	 * Sets the featured image of a post to an attachment, or removes it when $attachment_id is 0.
	 *
	 * Right before the write, the post is read again uncached, and $still_allowed is called with it: the caller's permission
	 * check runs again on the post as it is now. The stored thumbnail must still be $expected_current, or nothing is written
	 * (a lost update). After the write, the stored value is read back and reported.
	 *
	 * @param int      $post_id         Post ID.
	 * @param int      $attachment_id   Attachment ID, or 0 to remove the featured image.
	 * @param int      $expected_current The thumbnail ID the caller computed its change from, or 0 for none.
	 * @param callable $still_allowed   Receives the freshly read post, returns true when the caller may still make this change.
	 * @return array{featured_media: int}|\WP_Error The stored thumbnail ID after the write. Or an error: steadhand_forbidden
	 *                                              before any write, steadhand_refused_content_changed when the thumbnail
	 *                                              changed since the caller read it, and steadhand_update_partial when the
	 *                                              stored value after the write is not the one asked for.
	 * @throws \InvalidArgumentException When an ID is negative, or the post ID is not positive.
	 */
	public static function set_featured_image( int $post_id, int $attachment_id, int $expected_current, callable $still_allowed ): array|\WP_Error {
		self::assert_writing();

		if ( $post_id < 1 || $attachment_id < 0 || $expected_current < 0 ) {
			throw new \InvalidArgumentException( 'MediaWriter needs a post ID of 1 or more and attachment IDs of 0 or more.' );
		}

		// Read again, uncached: the post may have been trashed or changed since the caller looked at it.
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return self::forbidden();
		}

		// The permission check, run again on the post as it is now, immediately before the write.
		if ( ! $still_allowed( $post ) ) {
			return self::forbidden();
		}

		$current = self::stored_thumbnail( $post_id );
		if ( $current !== $expected_current ) {
			return new \WP_Error(
				'steadhand_refused_content_changed',
				__( "The post's featured image changed while this call was running, so nothing was changed. Call steadhand/get-post again, then send the change again.", 'steadhand' ),
				array( 'status' => 409 )
			);
		}

		if ( $current !== $attachment_id ) {
			if ( 0 === $attachment_id ) {
				delete_post_thumbnail( $post_id );
			} else {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}

		// The read-back: what the post stores now.
		$stored = self::stored_thumbnail( $post_id );
		if ( $stored !== $attachment_id ) {
			return new \WP_Error(
				'steadhand_update_partial',
				__( 'The featured image was changed, but not as asked, so the change was at least partly applied. Call steadhand/get-post to check the post before you continue.', 'steadhand' ),
				array( 'status' => 500 )
			);
		}

		return array( 'featured_media' => $stored );
	}

	/**
	 * The thumbnail ID the post stores, read from the database and not from the object cache. 0 when there is none. Reads
	 * only; it is not a write, so it needs no write context.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function stored_thumbnail( int $post_id ): int {
		wp_cache_delete( $post_id, 'post_meta' );

		return (int) get_post_meta( $post_id, '_thumbnail_id', true );
	}

	/**
	 * Removes what a failed create left behind: the attachment row and its files, or the file alone when no row exists.
	 *
	 * @param int    $id   Attachment ID, or 0 when no row was made.
	 * @param string $file Path of the stored file, or '' when core stored none.
	 */
	private static function remove_partial( int $id, string $file ): void {
		if ( $id > 0 ) {
			wp_delete_attachment( $id, true );
		}

		if ( '' !== $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Loads the admin functions core's sideload and metadata code need. They are not loaded in a REST or MCP request.
	 */
	private static function load_admin_includes(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * The refusal for a write that is not allowed, with one text.
	 *
	 * @return \WP_Error
	 */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
	}

	/**
	 * The error for a store that core could not finish.
	 *
	 * @return \WP_Error
	 */
	private static function storage_error(): \WP_Error {
		return new \WP_Error( 'steadhand_error', __( 'The image could not be stored. Try again, or check the site error log.', 'steadhand' ), array( 'status' => 500 ) );
	}

	/**
	 * Refuses a call made outside a write.
	 *
	 * @throws \LogicException When Pipeline::is_writing() is false.
	 */
	private static function assert_writing(): void {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'MediaWriter may only be called from a write ability.' );
		}
	}

	/**
	 * Checks that the fields are allowlisted and that every value is a string.
	 *
	 * @param array<mixed, mixed> $fields Fields.
	 * @throws \InvalidArgumentException When a key is not allowlisted, or a value is not a string.
	 */
	private static function assert_fields( array $fields ): void {
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, self::FIELDS, true ) ) {
				throw new \InvalidArgumentException( 'MediaWriter does not accept a field that is not on its allowlist.' );
			}

			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( 'MediaWriter needs every field value to be a string.' );
			}
		}
	}
}
