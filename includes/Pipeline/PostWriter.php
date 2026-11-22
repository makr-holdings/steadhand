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
 * - On insert, every post-field KSES filter is in place for the write, so everything the agent sends is filtered.
 * - On update, the KSES filters are in place for the fields the call sends, and removed for the fields it does not send.
 *   See update() for why an unsent field is saved as it is stored.
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
	 * added at priority 10, as core adds it. Each entry is the hook and the function.
	 */
	private const KSES_FILTERS = array(
		'post_title'            => array( 'title_save_pre', 'wp_filter_kses' ),
		'post_content'          => array( 'content_save_pre', 'wp_filter_post_kses' ),
		'post_excerpt'          => array( 'excerpt_save_pre', 'wp_filter_post_kses' ),
		'post_content_filtered' => array( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
	);

	/**
	 * Core's Global Styles filter for the two content fields. kses_init_filters() adds it at priority 9, next to the KSES
	 * filters. It changes only a value that holds Global Styles JSON. For an unsent field it is removed with the KSES
	 * filters, so the stored bytes are saved as they are.
	 */
	private const GLOBAL_STYLES_FILTERS = array(
		'post_content'          => array( 'content_save_pre', 'wp_filter_global_styles_post', 9 ),
		'post_content_filtered' => array( 'content_filtered_save_pre', 'wp_filter_global_styles_post', 9 ),
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
	 * So for each of the title, content, excerpt and filtered content the call does not send, the KSES filters (and the
	 * Global Styles filter on the content fields) are removed for the save, and restored afterwards. The stored bytes are
	 * saved unchanged. The agent cannot change those values: UPDATE_FIELDS does not allow them, and every value the agent
	 * has ever sent was filtered when it was stored. A stored value that a non-core filter on this site would change is
	 * refused before the write (would_alter_unsent()).
	 *
	 * The fields the call does send keep their KSES filters, which are added for the save where they are missing.
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

		$data       = self::dated( $fields );
		$data['ID'] = $post->ID;

		// Core gives a draft that has no date the time it is published. edit_date makes core use the date it is given, so it
		// is set only when the caller sends one. Otherwise a draft published later keeps no old date.
		if ( array_key_exists( 'post_date_gmt', $fields ) ) {
			$data['edit_date'] = true;
		}

		$sent   = array();
		$unsent = array();
		foreach ( self::KSES_FILTERS as $field => $pair ) {
			if ( array_key_exists( $field, $fields ) ) {
				$sent[] = $pair;
				continue;
			}

			$unsent[] = $pair;
			if ( isset( self::GLOBAL_STYLES_FILTERS[ $field ] ) ) {
				$unsent[] = self::GLOBAL_STYLES_FILTERS[ $field ];
			}
		}

		return self::with_filters_added(
			$sent,
			static fn(): int|\WP_Error => self::without_filters(
				$unsent,
				static fn(): int|\WP_Error => wp_update_post( wp_slash( $data ), true )
			)
		);
	}

	/**
	 * The fields of a stored post that WordPress would store differently on its next save, because of a save filter that is
	 * not core's KSES filter. The KSES filters are not run here: as update() does, they are removed for every field the call
	 * does not send. So core alone reports nothing for any stored value, whatever markup it holds.
	 *
	 * A field that a non-core filter (for example, a plugin's content_save_pre filter) would rewrite is refused by UpdatePost
	 * before anything is written. The title, content, excerpt and filtered content are checked, and those in $fields are not:
	 * the call replaces them.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $fields  Fields the update will set, keyed by post field name.
	 * @return string[] The post field names that would change, such as post_title. Empty when none would.
	 */
	public static function would_alter_unsent( int $post_id, array $fields ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$unsent = array();
		$strip  = array();
		foreach ( self::KSES_FILTERS as $field => $pair ) {
			if ( array_key_exists( $field, $fields ) ) {
				continue;
			}

			$unsent[] = $field;
			$strip[]  = $pair;
			if ( isset( self::GLOBAL_STYLES_FILTERS[ $field ] ) ) {
				$strip[] = self::GLOBAL_STYLES_FILTERS[ $field ];
			}
		}

		$altered = array();
		if ( array() === $unsent ) {
			return $altered;
		}

		self::without_filters(
			$strip,
			static function () use ( $post, $unsent, &$altered ): void {
				foreach ( $unsent as $field ) {
					$stored = (string) $post->$field;
					if ( self::as_saved( $field, $stored ) !== $stored ) {
						$altered[] = $field;
					}
				}
			}
		);

		return $altered;
	}

	/**
	 * What a field holds after the save filters, as sanitize_post_field() applies them on a save: the pre_ filter, then
	 * the _save_pre filter, each given the slashed value that core passes.
	 *
	 * @param string $field The post field, such as post_content.
	 * @param string $value The stored value.
	 * @return string
	 */
	private static function as_saved( string $field, string $value ): string {
		$short = str_replace( 'post_', '', $field );

		// Core's own save filters, so their names are not ours to prefix.
		$value = wp_unslash( apply_filters( "pre_{$field}", wp_slash( $value ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core's pre_ filter.

		return (string) wp_unslash( apply_filters( "{$short}_save_pre", wp_slash( $value ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core's _save_pre filter.
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
	 * Runs one step with the given filters removed, and afterwards restores each one that was removed, at the priority it
	 * had. A filter that was not there is not added. Removed filters are restored in finally, so a write that throws
	 * leaves them as they were.
	 *
	 * @param array<int, array{0: string, 1: string, 2?: int}> $pairs Filters to remove: hook, function and, optionally, priority.
	 * @param callable                                         $step  The step, a write or a check.
	 * @return mixed What the step returned.
	 */
	private static function without_filters( array $pairs, callable $step ): mixed {
		$removed = array();
		foreach ( $pairs as $pair ) {
			do {
				$priority = has_filter( $pair[0], $pair[1] );
				if ( false === $priority || ! remove_filter( $pair[0], $pair[1], $priority ) ) {
					break;
				}

				$removed[] = array( $pair[0], $pair[1], $priority );
			} while ( true );
		}

		try {
			return $step();
		} finally {
			foreach ( array_reverse( $removed ) as $item ) {
				add_filter( $item[0], $item[1], $item[2] );
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
