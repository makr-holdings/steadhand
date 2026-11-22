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
 * - The four post-field KSES filters are in place for every write, insert or update, for every field. Steadhand never
 *   removes a KSES filter or a Global Styles filter.
 * - On update, a field the call does not send is put back as it is stored, for that one post. See update().
 */
final class PostWriter {

	/** Fields an insert may set. */
	private const INSERT_FIELDS = array( 'post_type', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date_gmt' );

	/** Fields an update may set. */
	private const UPDATE_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date_gmt' );

	/** The zero GMT date WordPress stores for "no date". */
	private const ZERO_DATE = '0000-00-00 00:00:00';

	/**
	 * The save filters core adds for post fields (kses_init_filters() in wp-includes/kses.php), by post field. Each is
	 * added at priority 10, as core adds it. Each entry is the hook and the function. Every write has all four in place.
	 */
	private const KSES_FILTERS = array(
		'post_title'            => array( 'title_save_pre', 'wp_filter_kses' ),
		'post_content'          => array( 'content_save_pre', 'wp_filter_post_kses' ),
		'post_excerpt'          => array( 'excerpt_save_pre', 'wp_filter_post_kses' ),
		'post_content_filtered' => array( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
	);

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

		return self::with_filters_added(
			array_values( self::KSES_FILTERS ),
			static fn(): int|\WP_Error => wp_insert_post( wp_slash( $data ), true )
		);
	}

	/**
	 * Updates a post as the current user. The author and type stay as they are.
	 *
	 * Core's wp_update_post() saves every stored field again, through the save filters, not only the fields the call sends.
	 * The four KSES filters are in place for the whole write, for every field, as they are for an insert. No filter is
	 * removed for any field, so no code that runs during the save, such as a save_post listener that writes another post or
	 * a revision insert, runs without them.
	 *
	 * To keep the fields the call does not send byte-identical, update() records their stored values before the write: the
	 * title, content, excerpt and filtered content that $fields does not name. A wp_insert_post_data filter at PHP_INT_MAX puts
	 * each one back, after the save filters have run, and sets it slashed, because core passes $data slashed to that filter
	 * and unslashes it afterwards. The filter acts only on this post's own update (the update flag, the ID and the stored
	 * post type must all match), and only the first time it matches, so a nested update of the same post from a save_post
	 * listener is filtered as usual and is not given these values again. It is removed in finally, whatever happens. The
	 * fields the call sends are never touched by it: they keep the value core computes with the KSES filters.
	 *
	 * The one limit: code that changes the row after wp_insert_post_data (a filter that runs later at PHP_INT_MAX, or a direct
	 * write to the table) can still alter an unsent field. UpdatePost reads the row again after the write and reports
	 * steadhand_update_partial when such a field differs from what was stored before the write.
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

		// Read again, uncached: the post may have been trashed or changed since the caller looked at it, and the values read
		// here are the ones put back below.
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! PostTypes::is_allowed( $post->post_type ) ) {
			return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
		}

		$data       = self::dated( $fields );
		$data['ID'] = $post->ID;

		// Core gives a draft that has no date the time it is published. edit_date makes core use the date it is given, so it
		// is set only when the caller sends one. Otherwise a draft published later keeps no old date.
		if ( array_key_exists( 'post_date_gmt', $fields ) ) {
			$data['edit_date'] = true;
		}

		// The stored value of each field the call does not send, as the raw row holds it.
		$keep = array();
		foreach ( array_keys( self::KSES_FILTERS ) as $field ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				$keep[ $field ] = (string) $post->$field;
			}
		}

		$target_id = (int) $post->ID;
		$post_type = (string) $post->post_type;
		$armed     = true;
		$restore   = static function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( &$armed, $target_id, $post_type, $keep ): array {
			unset( $unsanitized_postarr );

			if ( ! $armed || ! $update || (int) ( $postarr['ID'] ?? 0 ) !== $target_id || ( $data['post_type'] ?? null ) !== $post_type ) {
				return $data;
			}

			// Once only: a nested update of this post, from a save_post listener, must not get these values again.
			$armed = false;
			foreach ( $keep as $field => $value ) {
				if ( array_key_exists( $field, $data ) ) {
					// $data is slashed here, as core passes it. Core unslashes it after this filter, so the stored bytes come back.
					$data[ $field ] = wp_slash( $value );
				}
			}

			return $data;
		};

		add_filter( 'wp_insert_post_data', $restore, PHP_INT_MAX, 4 );
		try {
			return self::with_filters_added(
				array_values( self::KSES_FILTERS ),
				static fn(): int|\WP_Error => wp_update_post( wp_slash( $data ), true )
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $restore, PHP_INT_MAX );
		}
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
	 * Runs one step with the given filters added where they are missing, and afterwards removes only the ones this call
	 * added, so a filter another component added stays as it was. Each pair is hook and function, at priority 10.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs Filters to have in place.
	 * @param callable                                $step  The step, a write.
	 * @return mixed What the step returned.
	 */
	private static function with_filters_added( array $pairs, callable $step ): mixed {
		$added = array();
		foreach ( $pairs as $pair ) {
			if ( false === has_filter( $pair[0], $pair[1] ) ) {
				add_filter( $pair[0], $pair[1] );
				$added[] = $pair;
			}
		}

		try {
			return $step();
		} finally {
			foreach ( array_reverse( $added ) as $pair ) {
				remove_filter( $pair[0], $pair[1] );
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
