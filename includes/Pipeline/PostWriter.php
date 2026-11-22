<?php
/**
 * The only place Steadhand writes posts: inserts and updates, with a fixed set of fields.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

use Steadhand\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Writes posts for the write abilities (SPEC §14.1, CLAUDE.md "Layout" rule). Every post write in Steadhand goes through
 * here, and only while Pipeline::is_writing() is true.
 *
 * Rules that hold for every write:
 * - Only the fields listed in INSERT_FIELDS or UPDATE_FIELDS are accepted. Anything else (post_author, post_password,
 *   ID, meta_input, tax_input, post_parent and so on) is an InvalidArgumentException.
 * - post_author is always the current user on insert, and is never passed on update. post_type is never passed on
 *   update.
 * - post_type must be allowed (PostTypes::is_allowed()). An update to a post whose stored type is not allowed, or to a
 *   trashed post, is refused.
 * - Content is filtered by KSES. Agents do not hold unfiltered_html, but this does not rely on that: the filters are
 *   added for the write if they are missing, and removed afterwards only if this class added them.
 */
final class PostWriter {

	/** Fields an insert may set. */
	private const INSERT_FIELDS = array( 'post_type', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date_gmt' );

	/** Fields an update may set. */
	private const UPDATE_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date_gmt' );

	/** The zero GMT date WordPress stores for "no date". */
	private const ZERO_DATE = '0000-00-00 00:00:00';

	/**
	 * Creates a post as the current user.
	 *
	 * @param array<string, string> $fields Fields to set. post_type is required and must be allowed.
	 * @return int|\WP_Error The new post ID, or the error WordPress reported.
	 * @throws \LogicException            When called outside a write.
	 * @throws \InvalidArgumentException  When a field is not allowed, or a value is not a string.
	 */
	public static function insert( array $fields ): int|\WP_Error {
		self::assert_writing();
		self::assert_fields( $fields, self::INSERT_FIELDS );

		$type = $fields['post_type'] ?? '';
		if ( ! is_string( $type ) || ! PostTypes::is_allowed( $type ) ) {
			return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
		}

		$data                = self::dated( $fields );
		$data['post_type']   = $type;
		$data['post_author'] = get_current_user_id();

		return self::with_kses(
			static fn(): int|\WP_Error => wp_insert_post( wp_slash( $data ), true )
		);
	}

	/**
	 * Updates a post as the current user. The author and type stay as they are.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $fields  Fields to set.
	 * @return int|\WP_Error The post ID, or the error WordPress reported.
	 * @throws \LogicException            When called outside a write.
	 * @throws \InvalidArgumentException  When a field is not allowed, or a value is not a string.
	 */
	public static function update( int $post_id, array $fields ): int|\WP_Error {
		self::assert_writing();
		self::assert_fields( $fields, self::UPDATE_FIELDS );

		// Checked again here, immediately before the write: the post may have been trashed or changed since the caller
		// looked at it.
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! PostTypes::is_allowed( $post->post_type ) ) {
			return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
		}

		$data              = self::dated( $fields );
		$data['ID']        = $post->ID;
		$data['edit_date'] = true;

		return self::with_kses(
			static fn(): int|\WP_Error => wp_update_post( wp_slash( $data ), true )
		);
	}

	/**
	 * Whether a stored post's title, content or excerpt differs from what was submitted. Compares only the fields that
	 * were submitted, byte for byte. A missing post counts as different.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $fields  Fields that were submitted, unslashed.
	 * @return bool
	 */
	public static function stored_differs( int $post_id, array $fields ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return true;
		}

		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( array_key_exists( $field, $fields ) && (string) $fields[ $field ] !== (string) $post->$field ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds the local date that matches a GMT date, so the two agree. Leaves the zero date alone.
	 *
	 * @param array<string, string> $fields Fields.
	 * @return array<string, string>
	 */
	private static function dated( array $fields ): array {
		$gmt = $fields['post_date_gmt'] ?? '';
		if ( '' !== $gmt && self::ZERO_DATE !== $gmt ) {
			$fields['post_date']     = get_date_from_gmt( $gmt );
			$fields['post_date_gmt'] = $gmt;
		}

		return $fields;
	}

	/**
	 * Runs one write with KSES filters in place, and removes the filters afterwards only if this call added them.
	 *
	 * @param callable $write The write.
	 * @return int|\WP_Error What the write returned.
	 */
	private static function with_kses( callable $write ): int|\WP_Error {
		$added = ! has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $added ) {
			kses_init_filters();
		}

		try {
			return $write();
		} finally {
			if ( $added ) {
				kses_remove_filters();
			}
		}
	}

	/**
	 * Refuses a call made outside a write.
	 *
	 * @throws \LogicException When Pipeline::is_writing() is false.
	 */
	private static function assert_writing(): void {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'PostWriter may only be called from a write ability.' );
		}
	}

	/**
	 * Checks that every key is allowed and every value is a string.
	 *
	 * @param array<mixed, mixed> $fields  Fields.
	 * @param string[]            $allowed Allowed keys.
	 * @throws \InvalidArgumentException When a key is not allowed, or a value is not a string.
	 */
	private static function assert_fields( array $fields, array $allowed ): void {
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				throw new \InvalidArgumentException( 'PostWriter does not accept a field that is not on its allowlist.' );
			}

			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( 'PostWriter needs every field value to be a string.' );
			}
		}
	}
}
