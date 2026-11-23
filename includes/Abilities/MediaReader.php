<?php
/**
 * Shared code for the media tools: the attachment read rule, the output shape and the sizes.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Media library items are posts of type attachment. The post tools never read them (PostTypes excludes them), so the
 * media tools reach them only through this class.
 *
 * What the media tools return, and what they never return:
 * - URLs only. The server path of a file (file, _wp_attached_file, the upload directory) is never returned.
 * - Dimensions, duration, file size and the generated sizes come from the attachment's metadata. Raw metadata is not
 *   returned, and neither is image_meta: EXIF, IPTC and GPS fields (camera, location, author, copyright).
 * - Files are public URLs, so an attachment is reported whatever its parent's status. The parent's ID is reported;
 *   nothing else about the parent is.
 * - No user email or login, and no post meta other than the alt text.
 */
final class MediaReader {

	/**
	 * Largest description get-media returns, in bytes, after tags are stripped.
	 */
	public const MAX_DESCRIPTION_BYTES = 5000;

	/**
	 * Most generated image sizes get-media returns.
	 */
	public const MAX_SIZES = 30;

	/**
	 * Longest MIME type the output schema allows, in characters. Longer stored values are cut, or, for sizes, skipped.
	 */
	public const MAX_MIME_LENGTH = 100;

	/**
	 * Longest top-level media type the output schema allows, in bytes.
	 */
	public const MAX_MEDIA_TYPE_LENGTH = 20;

	/**
	 * Longest title, alt text or caption returned, in bytes. Longer values are cut on a UTF-8 boundary.
	 */
	public const MAX_LABEL_BYTES = 1000;

	/**
	 * Largest width or height returned, in pixels.
	 */
	public const MAX_DIMENSION = 1000000;

	/**
	 * A MIME type as type/subtype, both made of RFC 2045 token characters. Matched against the stored value.
	 */
	private const MIME_PATTERN = '#^([!\#$&^_.+\-A-Za-z0-9]+)/[!\#$&^_.+\-A-Za-z0-9]+$#';

	/**
	 * The one per-object read check for the media tools (SPEC §14.1, layer one). Returns the attachment only when all of
	 * these hold: the ID is a post, its type is attachment, it is within the media rule (within_rule()), and the current
	 * user passes read_post.
	 *
	 * Both media tools get their attachment here, in authorize_object(), in execute() and for each row of list-media.
	 *
	 * This is the layer that does not depend on the MetaCaps attachment rule. The type and trash checks run here
	 * whatever MetaCaps returns. The read_post check is core's: MetaCaps widens it for a non-trashed attachment inside a
	 * Steadhand call, and outside a call core's own answer applies. Refusals are not told apart.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post|null The attachment if the current user may read it, otherwise null.
	 */
	public static function readable_attachment( int $id ): ?\WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! self::within_rule( $post ) ) {
			return null;
		}

		return current_user_can( 'read_post', $post->ID ) ? $post : null;
	}

	/**
	 * The media rule's own conditions (SPEC §14.1), shared by both layers: readable_attachment() and the attachment branch
	 * of MetaCaps::map(). An attachment is within the rule only when its own status is exactly inherit and its parent
	 * passes parent_permits(). Trashed files, and files of a private or draft post of a type the agent may not read, are
	 * therefore refused even when core would allow them.
	 *
	 * @param \WP_Post $attachment Attachment.
	 */
	public static function within_rule( \WP_Post $attachment ): bool {
		return 'inherit' === $attachment->post_status && self::parent_permits( $attachment );
	}

	/**
	 * The parent condition of the media rule. An attachment passes when its parent is 0, when the parent post no longer
	 * exists, or when the parent is of an allowed type (PostTypes::is_allowed()) and is not in the trash. Any other parent
	 * status is fine, because the agent can read that post. A parent of a type registered with map_meta_cap off is not
	 * an allowed type, so its attachments are refused, published or private (SPEC §14.1). list-media's SQL
	 * (ListMedia::parent_rule_clauses()) must match this method, so the total counts the same rows the rows check keeps.
	 *
	 * @param \WP_Post $attachment Attachment.
	 */
	public static function parent_permits( \WP_Post $attachment ): bool {
		$parent_id = (int) $attachment->post_parent;
		if ( 0 === $parent_id ) {
			return true;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent instanceof \WP_Post ) {
			return true;
		}

		return PostTypes::is_allowed( $parent->post_type ) && 'trash' !== $parent->post_status;
	}

	/**
	 * The properties of one media item, shared by list-media and get-media.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function item_properties(): array {
		return array(
			'id'           => PostReader::id_schema(),
			'title'        => array( 'type' => 'string' ),
			'slug'         => array( 'type' => 'string' ),
			'mime_type'    => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'media_type'   => array(
				'type'      => 'string',
				'maxLength' => 20,
			),
			'url'          => array( 'type' => 'string' ),
			'alt'          => array( 'type' => 'string' ),
			'caption'      => array( 'type' => 'string' ),
			'date_gmt'     => PostReader::date_schema(),
			'modified_gmt' => PostReader::date_schema(),
			'author'       => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id', 'name' ),
				'properties'           => array(
					'id'   => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'name' => array( 'type' => 'string' ),
				),
			),
			'parent'       => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'width'        => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 0,
			),
			'height'       => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 0,
			),
			'filesize'     => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 0,
			),
			'duration'     => array(
				'type'    => array( 'number', 'null' ),
				'minimum' => 0,
			),
		);
	}

	/**
	 * Schema for one media item, as list-media returns it.
	 *
	 * @return array<string, mixed>
	 */
	public static function item_schema(): array {
		$properties = self::item_properties();

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( $properties ),
			'properties'           => $properties,
		);
	}

	/**
	 * Schema for one generated image size.
	 *
	 * @return array<string, mixed>
	 */
	public static function size_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name', 'url', 'width', 'height', 'mime_type' ),
			'properties'           => array(
				'name'      => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'url'       => array( 'type' => 'string' ),
				'width'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'height'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'mime_type' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
		);
	}

	/**
	 * Builds the list fields for one attachment the caller may read.
	 *
	 * @param \WP_Post $post Attachment from readable_attachment().
	 * @return array<string, mixed>
	 */
	public static function item( \WP_Post $post ): array {
		$meta       = self::metadata( $post );
		$raw_mime   = (string) $post->post_mime_type;
		$media_type = self::media_type( $raw_mime );
		$url        = self::url( $post );
		$alt        = get_post_meta( (int) $post->ID, '_wp_attachment_image_alt', true );

		return array(
			'id'           => (int) $post->ID,
			'title'        => self::label( (string) $post->post_title ),
			'slug'         => PostReader::utf8( (string) $post->post_name ),
			'mime_type'    => self::mime_type( $raw_mime ),
			'media_type'   => $media_type,
			'url'          => $url,
			'alt'          => self::label( is_string( $alt ) ? $alt : '' ),
			'caption'      => self::label( (string) $post->post_excerpt ),
			'date_gmt'     => PostReader::iso_utc( (string) $post->post_date_gmt ),
			'modified_gmt' => PostReader::iso_utc( (string) $post->post_modified_gmt ),
			'author'       => PostReader::author( $post ),
			'parent'       => (int) $post->post_parent,
			'width'        => self::dimension( $meta, 'width', in_array( $media_type, array( 'image', 'video' ), true ) ),
			'height'       => self::dimension( $meta, 'height', in_array( $media_type, array( 'image', 'video' ), true ) ),
			'filesize'     => self::filesize( $meta ),
			'duration'     => self::duration( $meta, $media_type ),
		);
	}

	/**
	 * The description get-media returns: the content as plain text, tags and shortcodes removed, cut at
	 * MAX_DESCRIPTION_BYTES on a UTF-8 boundary.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array{value: string, truncated: bool}
	 */
	public static function description( \WP_Post $post ): array {
		$text  = PostReader::plain_text( wp_strip_all_tags( strip_shortcodes( PostReader::utf8( (string) $post->post_content ) ) ) );
		$bytes = strlen( $text );

		return array(
			'value'     => $bytes > self::MAX_DESCRIPTION_BYTES ? PostReader::cut_utf8( $text, self::MAX_DESCRIPTION_BYTES ) : $text,
			'truncated' => $bytes > self::MAX_DESCRIPTION_BYTES,
		);
	}

	/**
	 * The generated sizes of an image, as absolute URLs, in metadata order, at most MAX_SIZES. Non-images have none.
	 * The URL of each size is the URL of the original with the size's own file name, so no path is read from disk.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array<int, array{name: string, url: string, width: int, height: int, mime_type: string}>
	 */
	public static function sizes( \WP_Post $post ): array {
		$meta = self::metadata( $post );
		if ( self::media_type( (string) $post->post_mime_type ) !== 'image' || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return array();
		}

		$url = self::url( $post );
		if ( '' === $url ) {
			return array();
		}
		$base = trailingslashit( dirname( $url ) );

		$sizes = array();
		foreach ( $meta['sizes'] as $name => $size ) {
			if ( count( $sizes ) >= self::MAX_SIZES ) {
				break;
			}
			if ( ! is_array( $size ) || ! is_string( $name ) ) {
				continue;
			}

			$name = PostReader::utf8( $name );
			$mime = PostReader::utf8( is_string( $size['mime-type'] ?? null ) ? $size['mime-type'] : '' );
			$file = isset( $size['file'] ) && is_string( $size['file'] ) ? basename( $size['file'] ) : '';
			if ( mb_strlen( $name ) > 100 || mb_strlen( $mime ) > self::MAX_MIME_LENGTH || in_array( $file, array( '', '.', '..' ), true ) ) {
				// A size the output schema cannot hold, or one with no usable file name, is skipped. The others stay.
				continue;
			}

			$sizes[] = array(
				'name'      => $name,
				'url'       => $base . PostReader::utf8( $file ),
				'width'     => self::bounded_int( $size['width'] ?? null ) ?? 0,
				'height'    => self::bounded_int( $size['height'] ?? null ) ?? 0,
				'mime_type' => $mime,
			);
		}

		return $sizes;
	}

	/**
	 * The public URL of an attachment's file, or '' when no URL is safe to return (SPEC §14.1, FIX 3).
	 *
	 * Core falls back to the post GUID when it cannot build a URL from _wp_attached_file, and the GUID is any stored
	 * string (a site may have one that names an internal host). So the URL is built only when the file is stored, and
	 * when it is relative: core would embed an absolute server path in a URL. Upload-directory errors also give ''.
	 * Other hosts are not checked, because offloaded media legitimately uses them.
	 *
	 * Two guards, and each is needed on its own. Core tests the stored value for truthiness, so "0" makes it fall back to
	 * the GUID, and a blank value is refused here before core is asked. The second guard catches a URL equal to the GUID
	 * that does not name the stored file (see is_unverified_guid()), whatever the cause.
	 *
	 * @param \WP_Post $post Attachment.
	 */
	public static function url( \WP_Post $post ): string {
		$file = get_post_meta( (int) $post->ID, '_wp_attached_file', true );
		if ( ! is_string( $file ) || ! $file || '' === trim( $file ) || self::is_absolute_path( $file ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$url = wp_get_attachment_url( (int) $post->ID );
		if ( ! is_string( $url ) || '' === $url || self::is_unverified_guid( $url, $post, $file ) ) {
			return '';
		}

		return PostReader::utf8( $url );
	}

	/**
	 * Whether a URL core returned is the attachment's GUID while the GUID does not name the stored file.
	 *
	 * A fresh upload's GUID is its own file URL, so it ends with the file name and is kept. A GUID set to anything else,
	 * such as an internal host's address, is refused. The scheme is ignored in the comparison, because core may switch
	 * http to https on the front end.
	 *
	 * @param string   $url  URL from wp_get_attachment_url().
	 * @param \WP_Post $post Attachment.
	 * @param string   $file Stored value of _wp_attached_file.
	 */
	private static function is_unverified_guid( string $url, \WP_Post $post, string $file ): bool {
		$guid = (string) get_the_guid( $post->ID );
		if ( self::without_scheme( $url ) !== self::without_scheme( $guid ) ) {
			return false;
		}

		$name = wp_basename( $file );

		return '' === $name || ! str_ends_with( $url, $name );
	}

	/**
	 * A URL with its http or https scheme removed, for comparison.
	 *
	 * @param string $url URL.
	 */
	private static function without_scheme( string $url ): string {
		return (string) preg_replace( '#^https?://#i', '', $url );
	}

	/**
	 * Whether a stored file path is absolute: a leading slash or backslash (POSIX and UNC), or a Windows drive letter.
	 *
	 * @param string $file Stored path.
	 */
	private static function is_absolute_path( string $file ): bool {
		return 1 === preg_match( '#^(?:[\\\\/]|[A-Za-z]:)#', $file );
	}

	/**
	 * The metadata array WordPress stores for an attachment, or an empty array.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array<string, mixed>
	 */
	private static function metadata( \WP_Post $post ): array {
		$meta = wp_get_attachment_metadata( (int) $post->ID );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * The top-level type of a stored MIME type, such as image for image/jpeg. It is '' when the value is not type/subtype
	 * made of token characters, when it is longer than the schema allows, or when the top-level type is longer than 20
	 * bytes. Those cases are the malformed values, so no media type is guessed from them.
	 *
	 * @param string $mime MIME type as stored.
	 */
	public static function media_type( string $mime ): string {
		$mime = PostReader::utf8( $mime );
		if ( strlen( $mime ) > self::MAX_MIME_LENGTH || 1 !== preg_match( self::MIME_PATTERN, $mime, $match ) ) {
			return '';
		}

		return strlen( $match[1] ) > self::MAX_MEDIA_TYPE_LENGTH ? '' : $match[1];
	}

	/**
	 * The MIME type as returned: valid UTF-8, cut to MAX_MIME_LENGTH bytes (so also to that many characters).
	 *
	 * @param string $mime MIME type as stored.
	 */
	public static function mime_type( string $mime ): string {
		return PostReader::cut_utf8( PostReader::utf8( $mime ), self::MAX_MIME_LENGTH );
	}

	/**
	 * A title, alt text or caption as returned: plain text, tags removed and entities decoded, cut to MAX_LABEL_BYTES.
	 *
	 * @param string $raw Value as stored.
	 */
	public static function label( string $raw ): string {
		return PostReader::label_text( $raw, self::MAX_LABEL_BYTES );
	}

	/**
	 * An integer from metadata that is positive and no larger than MAX_DIMENSION, or null. Only real integers count.
	 *
	 * @param mixed $value Value as stored.
	 */
	private static function bounded_int( mixed $value ): ?int {
		return is_int( $value ) && $value > 0 && $value <= self::MAX_DIMENSION ? $value : null;
	}

	/**
	 * A stored width or height, when the media type has one.
	 *
	 * @param array<string, mixed> $meta      Metadata.
	 * @param string               $key       width or height.
	 * @param bool                 $applies   Whether the media type has dimensions (images and videos).
	 */
	private static function dimension( array $meta, string $key, bool $applies ): ?int {
		$value = $meta[ $key ] ?? null;

		return $applies ? self::bounded_int( $value ) : null;
	}

	/**
	 * The file size in bytes, if the metadata stores it. The file is never read from disk.
	 *
	 * @param array<string, mixed> $meta Metadata.
	 */
	private static function filesize( array $meta ): ?int {
		$value = $meta['filesize'] ?? null;

		return is_int( $value ) && $value > 0 ? $value : null;
	}

	/**
	 * The length in seconds of audio or video, if the metadata stores it.
	 *
	 * @param array<string, mixed> $meta       Metadata.
	 * @param string               $media_type Top-level MIME type.
	 * @return int|float|null
	 */
	private static function duration( array $meta, string $media_type ): int|float|null {
		$value = $meta['length'] ?? null;
		if ( ! in_array( $media_type, array( 'audio', 'video' ), true ) || ! ( is_int( $value ) || is_float( $value ) ) ) {
			return null;
		}

		// NAN and INF cannot be encoded as JSON, so only a finite, non-negative number is returned.
		return is_finite( (float) $value ) && $value >= 0 ? $value : null;
	}
}
