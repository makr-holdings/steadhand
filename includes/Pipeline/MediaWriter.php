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
 * The type, size, pixel, container and name rules are the ability's (UploadMedia). This class checks what it needs to be
 * safe on its own: that it is called inside a write, the shape of its arguments, that the image fits the memory the site
 * allows, and that what core stored is the checked bytes. Steadhand does not decode the image itself. Core decodes it when
 * it makes the sizes, which can happen more than once for a large or rotated image.
 */
final class MediaWriter {

	/**
	 * The extension-to-type map passed to core's sideload as its allowlist. The two types UploadMedia accepts.
	 */
	private const MIMES = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
	);

	/**
	 * The extensions a stored file may have. Checked again after core has stored it.
	 */
	private const EXTENSIONS = array( 'jpg', 'jpeg', 'png' );

	/**
	 * The fields create_image() takes. Anything else is refused.
	 */
	private const FIELDS = array( 'title', 'alt', 'caption' );

	/**
	 * Memory the decoder and the request need beyond the pixels: 64 MB.
	 */
	private const MEMORY_OVERHEAD = 67108864;

	/**
	 * Memory one pixel takes while the image is decoded.
	 */
	private const BYTES_PER_PIXEL = 8;

	/**
	 * What the crash cleanup removes, while a create_image() call is running. Null when none is.
	 *
	 * The payload's SHA-256 is recorded when the write is armed. 'written' is the path core first gives the stored file, before
	 * any filter changes it. 'file' is the path the write continues with. 'sizes' lists the generated sizes and the copy core
	 * made in place of the original, when there is one.
	 *
	 * @var array{tmp: string, file: string, written: string, id: int, sizes: string[], sha256: string}|null
	 */
	private static ?array $pending = null;

	/**
	 * Whether the shutdown function is registered. It is registered once, and does nothing while $pending is null.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Stores image bytes as a new attachment in the media library, with its metadata and generated sizes.
	 *
	 * The header must be a JPEG or PNG, and the image must fit the memory limit, both checked before anything is written. The
	 * bytes go to an exclusively created temporary file, which core's sideload moves into the uploads directory under a name it
	 * makes unique. After the sideload, the type, the extension, the content type, the location and the SHA-256 of the stored
	 * file are checked again. $still_allowed runs right before the row is inserted; when it returns false nothing is stored.
	 * Core decodes the image when it makes the sizes; an image it cannot process is refused, with its file and row removed.
	 *
	 * Whatever happens, the temporary file is removed. A fatal error during the write is cleaned up by the filter this arms
	 * on core's fatal error handler, with a shutdown function as the fallback.
	 *
	 * @param string               $bytes         The image, already checked by the ability.
	 * @param string               $filename      The file name, already checked; its extension must match the bytes.
	 * @param array<string, mixed> $fields        Optional title, alt and caption (strings only).
	 * @param int                  $parent_id     Parent post ID, or 0.
	 * @param callable             $still_allowed Returns true when the caller may still attach the image to this parent.
	 * @return int|\WP_Error The new attachment's ID, or a WP_Error. steadhand_invalid_input when the image is refused
	 *                       before anything is stored, or core cannot process it; steadhand_forbidden when $still_allowed
	 *                       refuses; steadhand_error when a step core needs fails, or the stored file is not the checked bytes.
	 * @throws \InvalidArgumentException When a field is not allowlisted or not a string, or an argument is out of range.
	 */
	public static function create_image( string $bytes, string $filename, array $fields, int $parent_id, callable $still_allowed ): int|\WP_Error {
		self::assert_writing();
		self::assert_fields( $fields );

		if ( '' === $bytes || $parent_id < 0 ) {
			throw new \InvalidArgumentException( 'MediaWriter needs image bytes and a parent ID of 0 or more.' );
		}

		$name = sanitize_file_name( $filename );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'MediaWriter needs a file name.' );
		}

		// The header is read from the bytes in memory. Its type is the one the stored file must keep, and its size is what the
		// decoder will need. Both are known before the file is written or core touches it.
		$header = getimagesizefromstring( $bytes );
		if ( ! is_array( $header ) || ! in_array( (string) ( $header['mime'] ?? '' ), self::types(), true ) ) {
			return new \WP_Error(
				'steadhand_invalid_input',
				__( 'The image data could not be read as an image, so nothing was stored.', 'steadhand' ),
				array( 'status' => 400 )
			);
		}
		$expected = (string) $header['mime'];

		if ( ! self::fits_memory( (int) $header[0], (int) $header[1], self::memory_limit() ) ) {
			return new \WP_Error(
				'steadhand_invalid_input',
				__( 'The image needs more memory than this site allows, so nothing was stored.', 'steadhand' ),
				array( 'status' => 400 )
			);
		}

		self::load_admin_includes();

		$sha256 = hash( 'sha256', $bytes );
		$tmp    = self::write_temp_file( $bytes, $name );
		if ( '' === $tmp ) {
			return self::storage_error();
		}

		self::arm( $tmp, $sha256 );

		$file  = '';
		$id    = 0;
		$sizes = array();
		$done  = false;

		try {
			// Core takes the upload by reference, so it is a variable.
			$upload = array(
				'name'     => $name,
				'type'     => '',
				'tmp_name' => $tmp,
				'error'    => 0,
				'size'     => strlen( $bytes ),
			);
			$stored = wp_handle_sideload(
				$upload,
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

			$file                  = (string) $stored['file'];
			self::$pending['file'] = $file;

			// Core may have changed what it stored, through filters on its own checks. Each thing it returned is checked again,
			// and the bytes must be the ones that were checked.
			if ( ! self::stored_as_checked( $stored, $expected ) || ! self::same_bytes( $file, $sha256 ) ) {
				return new \WP_Error(
					'steadhand_error',
					__( 'WordPress stored this file as something other than the image that was checked, so nothing was stored.', 'steadhand' ),
					array( 'status' => 500 )
				);
			}

			$title = isset( $fields['title'] ) ? (string) $fields['title'] : (string) pathinfo( $name, PATHINFO_FILENAME );

			// The permission check, run again just before the row is made.
			if ( ! $still_allowed() ) {
				return self::forbidden();
			}

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
			$id                  = (int) $attachment;
			self::$pending['id'] = $id;

			// Core decodes the image here, when it makes the sizes. An image it cannot process comes back without its size.
			$meta = wp_generate_attachment_metadata( $id, $file );
			if ( ! is_array( $meta ) || array() === $meta ) {
				return self::storage_error();
			}
			$sizes = self::size_paths( $file, $meta );

			// For a large or rotated image core replaces the attached file with a copy in the same directory. The row points at
			// that copy now, so it is one of the files this write made.
			$attached = (string) get_attached_file( $id );
			if ( '' !== $attached && $attached !== $file ) {
				$sizes[] = $attached;
			}
			self::$pending['sizes'] = $sizes;

			if ( ! self::has_dimensions( $meta ) ) {
				return new \WP_Error(
					'steadhand_invalid_input',
					__( 'WordPress could not process this image, so nothing was stored.', 'steadhand' ),
					array( 'status' => 400 )
				);
			}

			wp_update_attachment_metadata( $id, $meta );

			if ( isset( $fields['alt'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $fields['alt'] );
			}

			$done = true;
			return $id;
		} finally {
			if ( $done ) {
				wp_delete_file( $tmp );
			} else {
				self::remove_unfinished( $tmp, $file, $id, $sizes, $sha256, (string) ( self::$pending['written'] ?? '' ) );
			}

			self::$pending = null;
			self::disarm();
		}
	}

	/**
	 * Sets the featured image of a post to an attachment, or removes it when $attachment_id is 0.
	 *
	 * Right before the write, the post is read again uncached, and $still_allowed is called with it: the caller's permission
	 * check runs again on the post as it is now. The stored thumbnail must still be $expected_current, or nothing is written
	 * (a lost update). After the write, the stored value is read back and reported.
	 *
	 * @param int      $post_id          Post ID.
	 * @param int      $attachment_id    Attachment ID, or 0 to remove the featured image.
	 * @param int      $expected_current The thumbnail ID the caller computed its change from, or 0 for none.
	 * @param callable $still_allowed    Receives the freshly read post, returns true when the caller may still make this change.
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
	 * The memory limit core would give an image operation, or -1 when there is none. Mirrors wp_raise_memory_limit( 'image' ):
	 * core raises the limit to the image_memory_limit value when that is higher than both the default and the current limit,
	 * or to the default when the default is higher than the current limit. It never lowers the limit. A current limit of -1
	 * is unlimited, and core changes nothing then.
	 *
	 * @return int Bytes, or -1.
	 */
	public static function memory_limit(): int {
		$current = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( -1 === $current || false === wp_is_ini_value_changeable( 'memory_limit' ) ) {
			return $current;
		}

		$max = wp_convert_hr_to_bytes( (string) WP_MAX_MEMORY_LIMIT );
		// The filter is core's own, which core applies in the same way.
		$filtered = wp_convert_hr_to_bytes( (string) apply_filters( 'image_memory_limit', WP_MAX_MEMORY_LIMIT ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's image_memory_limit filter, which core applies in the same way.

		if ( -1 === $filtered || ( $filtered > $max && $filtered > $current ) ) {
			return $filtered;
		}

		if ( -1 === $max || $max > $current ) {
			return $max;
		}

		return $current;
	}

	/**
	 * Whether an image of these pixel dimensions fits the limit: its pixels at 8 bytes each, plus the memory the request already
	 * uses, plus 64 MB, must not be more than the limit. A limit of -1 is unlimited. The check does not apply to Imagick, whose
	 * memory is not counted against PHP's limit.
	 *
	 * @param int $width  Width in pixels, from the header.
	 * @param int $height Height in pixels, from the header.
	 * @param int $limit  Limit in bytes, or -1.
	 * @return bool
	 */
	public static function fits_memory( int $width, int $height, int $limit ): bool {
		if ( -1 === $limit ) {
			return true;
		}

		$needed = $width * $height * self::BYTES_PER_PIXEL + memory_get_usage( true ) + self::MEMORY_OVERHEAD;

		return $needed <= $limit;
	}

	/**
	 * Removes what a write left behind: the temporary file, the stored file and the copy core made in its place, the generated
	 * sizes and the attachment row. Called when a write fails, from the fatal error handler, and from the shutdown fallback.
	 *
	 * An original file (the stored file, or the path core first gave it) is removed only when it is inside the uploads
	 * directory and its SHA-256 is the payload's. A generated size is removed only when it is inside the uploads directory,
	 * is in the same directory as the stored file, and was listed in the metadata. When a row is removed, WordPress's own
	 * deletion may remove only the files chosen above: every other path it offers to wp_delete_file() is kept. Paths outside
	 * their directories are never removed. Running it twice is harmless.
	 *
	 * @internal Public for the cleanup tests and the fatal error handler. Not an extension point.
	 * @param string   $tmp     The temporary file, or ''.
	 * @param string   $file    The stored file the write continued with, or ''.
	 * @param int      $id      The attachment row, or 0.
	 * @param string[] $sizes   Files of the generated sizes, and of the copy core made in place of the original.
	 * @param string   $sha256  The SHA-256 of the payload the write checked.
	 * @param string   $written The path core first gave the stored file, before any filter, or ''.
	 */
	public static function remove_unfinished( string $tmp, string $file, int $id, array $sizes, string $sha256, string $written ): void {
		if ( '' !== $tmp && self::inside( get_temp_dir(), $tmp ) ) {
			wp_delete_file( $tmp );
		}

		$base  = (string) wp_upload_dir()['basedir'];
		$owned = array();

		foreach ( array_unique( array( $file, $written ) ) as $original ) {
			if ( '' !== $original && self::inside( $base, $original ) && self::same_bytes( $original, $sha256 ) ) {
				$owned[] = (string) realpath( $original );
			}
		}

		$dir = '' === $file ? '' : self::real_dir( $file );
		foreach ( $sizes as $size ) {
			if ( '' !== $size && '' !== $dir && self::inside( $base, $size ) && self::real_dir( $size ) === $dir ) {
				$owned[] = (string) realpath( $size );
			}
		}
		$owned = array_values( array_unique( $owned ) );

		if ( $id > 0 ) {
			self::delete_row( $id, $owned );
		}

		foreach ( $owned as $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * The fatal error handler's hook, and the shutdown fallback's work, as one callback. Core applies the wp_php_error_args
	 * filter with the arguments it is about to pass to wp_die(), so the cleanup runs before the error page is written. It returns
	 * the arguments unchanged.
	 *
	 * @internal Attached while a write is armed. Not an extension point.
	 * @param array<string, mixed> $args The arguments core passes to wp_die().
	 * @return array<string, mixed>
	 */
	public static function finish_during_php_error( array $args ): array {
		self::finish_after_fatal();

		return $args;
	}

	/**
	 * The shutdown function, and the work of the fatal error filter. A PHP fatal error skips the finally block, so when a write
	 * did not finish, this removes what it left. Does nothing when no write is running, or the write finished and cleared its
	 * state. Running it again does nothing more.
	 *
	 * @internal Registered by arm(). Not an extension point.
	 */
	public static function finish_after_fatal(): void {
		$pending       = self::$pending;
		self::$pending = null;

		if ( null === $pending ) {
			return;
		}

		self::remove_unfinished( $pending['tmp'], $pending['file'], $pending['id'], $pending['sizes'], $pending['sha256'], $pending['written'] );
	}

	/**
	 * Records the path core gives the stored file, before any other filter can change it. Runs only while a write is armed.
	 *
	 * @internal Attached while a write is armed. Not an extension point.
	 * @param array<string, mixed> $upload  What the sideload returns.
	 * @param string               $context The sideload context.
	 * @return array<string, mixed>
	 */
	public static function record_written_file( array $upload, string $context ): array {
		if ( 'sideload' === $context && null !== self::$pending && is_string( $upload['file'] ?? null ) ) {
			self::$pending['written'] = $upload['file'];
		}

		return $upload;
	}

	/**
	 * Removes the attachment row, and only the files this class chose: every other path WordPress's own deletion offers to
	 * wp_delete_file() is kept.
	 *
	 * @param int      $id    The attachment row.
	 * @param string[] $owned Real paths that may be removed.
	 */
	private static function delete_row( int $id, array $owned ): void {
		$only_owned = static function ( $path ) use ( $owned ): string {
			$real = is_string( $path ) ? realpath( $path ) : false;

			return false !== $real && in_array( $real, $owned, true ) ? $path : '';
		};

		add_filter( 'wp_delete_file', $only_owned );
		try {
			wp_delete_attachment( $id, true );
		} finally {
			remove_filter( 'wp_delete_file', $only_owned );
		}
	}

	/**
	 * Writes the bytes to a new temporary file. The file is created exclusively, so an existing path is never written to or
	 * followed; a name that is taken is tried again with a new random part. The file is readable by its owner only. The random
	 * part comes from random_bytes(), not from wp_generate_password(), which a site's filters can change.
	 *
	 * @param string $bytes The image.
	 * @param string $name  The file name, which keeps the extension the image editor chooses its handler by.
	 * @return string The path, or '' when no file could be created.
	 */
	private static function write_temp_file( string $bytes, string $name ): string {
		$dir = trailingslashit( get_temp_dir() );

		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$path = $dir . 'steadhand-' . bin2hex( random_bytes( 16 ) ) . '-' . $name;

			// 'x' fails when the path exists, a link included, so an existing path is never written to.
			$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive create; a taken name is retried below.
			if ( false === $handle ) {
				continue;
			}

			$mode    = chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The temporary file is owner-only.
			$written = fwrite( $handle, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The temporary file.
			$closed  = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The temporary file.

			if ( ! $mode || strlen( $bytes ) !== $written || ! $closed ) {
				wp_delete_file( $path );
				return '';
			}

			return $path;
		}

		return '';
	}

	/**
	 * Whether core's result is the image that was checked: the type is one of the two, the extension is allowed, the
	 * content is still the checked type, and the file is inside the uploads directory.
	 *
	 * @param array<string, mixed> $stored   What sideload returned.
	 * @param string               $expected The type the header gave.
	 * @return bool
	 */
	private static function stored_as_checked( array $stored, string $expected ): bool {
		$file = (string) $stored['file'];
		$real = realpath( $file );
		$base = realpath( (string) wp_upload_dir()['basedir'] );

		return in_array( (string) $stored['type'], self::types(), true )
			&& in_array( strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true )
			&& wp_get_image_mime( $file ) === $expected
			&& false !== $real
			&& false !== $base
			&& str_starts_with( $real, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
	}

	/**
	 * Whether the file exists and its SHA-256 is the given one. An empty digest never matches.
	 *
	 * @param string $file   The file.
	 * @param string $sha256 The digest.
	 * @return bool
	 */
	private static function same_bytes( string $file, string $sha256 ): bool {
		return '' !== $sha256 && is_file( $file ) && hash_file( 'sha256', $file ) === $sha256;
	}

	/**
	 * The file paths of the generated sizes, in the directory of the stored file. A name with a directory part is reduced to
	 * its last part.
	 *
	 * @param string               $file The stored file.
	 * @param array<string, mixed> $meta The metadata core made.
	 * @return string[]
	 */
	private static function size_paths( string $file, array $meta ): array {
		$paths = array();

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && '' !== $size['file'] ) {
				$paths[] = trailingslashit( dirname( $file ) ) . basename( $size['file'] );
			}
		}

		return $paths;
	}

	/**
	 * Whether the metadata has a width and a height above zero.
	 *
	 * @param array<string, mixed> $meta Metadata core made.
	 * @return bool
	 */
	private static function has_dimensions( array $meta ): bool {
		return isset( $meta['width'], $meta['height'] ) && (int) $meta['width'] > 0 && (int) $meta['height'] > 0;
	}

	/**
	 * Whether $path, after its symbolic links are resolved, lies inside $dir.
	 *
	 * @param string $dir  Directory.
	 * @param string $path Path to check. Must exist.
	 * @return bool
	 */
	private static function inside( string $dir, string $path ): bool {
		$base = realpath( $dir );
		$real = realpath( $path );

		return false !== $base && false !== $real && str_starts_with( $real, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
	}

	/**
	 * The real path of the directory a file is in, or '' when it cannot be resolved.
	 *
	 * @param string $path A file path.
	 * @return string
	 */
	private static function real_dir( string $path ): string {
		$dir = realpath( dirname( $path ) );

		return false === $dir ? '' : $dir;
	}

	/**
	 * Arms the crash cleanup for one write: records the state, attaches the fatal error filter and the path capture, and
	 * registers the shutdown fallback the first time.
	 *
	 * @param string $tmp    The temporary file.
	 * @param string $sha256 The SHA-256 of the payload.
	 */
	private static function arm( string $tmp, string $sha256 ): void {
		self::$pending = array(
			'tmp'     => $tmp,
			'file'    => '',
			'written' => '',
			'id'      => 0,
			'sizes'   => array(),
			'sha256'  => $sha256,
		);

		add_filter( 'wp_php_error_args', array( self::class, 'finish_during_php_error' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's fatal error handler filter. The callback runs the cleanup before core's error page.
		add_filter( 'wp_handle_upload', array( self::class, 'record_written_file' ), 0, 2 );

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( self::class, 'finish_after_fatal' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Detaches what arm() attached, when a write finishes.
	 */
	private static function disarm(): void {
		remove_filter( 'wp_php_error_args', array( self::class, 'finish_during_php_error' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's fatal error handler filter.
		remove_filter( 'wp_handle_upload', array( self::class, 'record_written_file' ), 0 );
	}

	/**
	 * The MIME types the writer accepts.
	 *
	 * @return string[]
	 */
	private static function types(): array {
		return array_values( self::MIMES );
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
