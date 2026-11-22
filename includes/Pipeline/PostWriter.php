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
 * - Content is filtered by KSES. Agents do not hold unfiltered_html, but this does not rely on that: the KSES filters
 *   for post fields are added for the write where they are missing, and only those are removed afterwards.
 * - wp_update_post() re-saves every stored field through the same filters, including the fields the call did not send.
 *   would_alter_unsent() finds the fields whose stored value the save would change. UpdatePost refuses such a call.
 */
final class PostWriter {

	/** Fields an insert may set. */
	private const INSERT_FIELDS = array( 'post_type', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date_gmt' );

	/** Fields an update may set. */
	private const UPDATE_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date_gmt' );

	/** The zero GMT date WordPress stores for "no date". */
	private const ZERO_DATE = '0000-00-00 00:00:00';

	/**
	 * The KSES filters core adds for post fields (kses_init_filters() in wp-includes/kses.php): the hook and the function.
	 * Only the title, content, excerpt and content_filtered pairs are used. Each is added at priority 10, as core does.
	 */
	private const KSES_FILTERS = array(
		array( 'title_save_pre', 'wp_filter_kses' ),
		array( 'content_save_pre', 'wp_filter_post_kses' ),
		array( 'excerpt_save_pre', 'wp_filter_post_kses' ),
		array( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
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

		$data       = self::dated( $fields );
		$data['ID'] = $post->ID;

		// Core gives a draft that has no date the time it is published. edit_date makes core use the date it is given, so it
		// is set only when the caller sends one. Otherwise a draft published later keeps no old date.
		if ( array_key_exists( 'post_date_gmt', $fields ) ) {
			$data['edit_date'] = true;
		}

		return self::with_kses(
			static fn(): int|\WP_Error => wp_update_post( wp_slash( $data ), true )
		);
	}

	/**
	 * The fields of a stored post that WordPress would store differently on its next save. wp_update_post() runs every
	 * stored field through the save filters, so a title, content or excerpt that the call does not send can change: for
	 * example, a post an administrator wrote with an iframe, which KSES removes for an agent. Only the title, content and
	 * excerpt are checked. Those in $fields are not, because the call replaces them.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $fields  Fields the update will set, keyed by post field name.
	 * @return string[] The field names (post_title, post_content, post_excerpt) that would change. Empty when none would.
	 */
	public static function would_alter_unsent( int $post_id, array $fields ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$altered = array();
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				continue;
			}

			$stored = (string) $post->$field;
			$saved  = self::with_kses(
				static fn(): string => self::as_saved( $field, $stored )
			);

			if ( $saved !== $stored ) {
				$altered[] = $field;
			}
		}

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
	 * Runs one step with the post-field KSES filters in place. Each KSES filter is added only where it is missing, and
	 * afterwards only the ones this call added are removed, so a filter another component added stays as it was. The
	 * filters are not added or removed with kses_init_filters() and kses_remove_filters(), which touch every KSES hook.
	 *
	 * @param callable $step The step: a write, or a check.
	 * @return mixed What the step returned.
	 */
	private static function with_kses( callable $step ): mixed {
		$added = array();
		foreach ( self::KSES_FILTERS as $pair ) {
			list( $hook, $function ) = $pair;
			if ( false === has_filter( $hook, $function ) ) {
				add_filter( $hook, $function );
				$added[] = $pair;
			}
		}

		try {
			return $step();
		} finally {
			foreach ( $added as $pair ) {
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
