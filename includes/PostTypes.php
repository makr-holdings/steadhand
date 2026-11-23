<?php
/**
 * Which post types Steadhand's post tools may read and write.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Post type allowlist (SPEC §5, §14.1). Default: post and page. Sites and developers may change the list through the
 * steadhand_allowed_post_types filter (documented in docs/HOOKS.md), but never add an always-excluded type back.
 * Every built-in type other than post and page is excluded too, so a type WordPress adds later is excluded without a
 * change here. A type registered with map_meta_cap off is excluded as well, whatever the filter returns: core makes none
 * of its per-post checks for such a type (SPEC §14.1).
 */
final class PostTypes {

	/**
	 * Post types that Steadhand never touches, whatever the filter returns, in addition to every built-in type other
	 * than post and page (see allowed()). Attachments belong to the media tools; the rest are site-building types.
	 */
	public const ALWAYS_EXCLUDED = array( 'attachment', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block', 'wp_global_styles', 'nav_menu_item', 'revision' );

	/**
	 * Returns the post types agents may use, after the filter and the safety checks.
	 *
	 * Every value the filter returns is checked. A non-array result falls back to the default. Each value must be a
	 * string that sanitize_key() leaves unchanged, that names a registered post type, that is not always excluded, that
	 * is not a built-in post type other than post and page, and that is registered with map_meta_cap on (see
	 * uses_meta_caps()). Duplicates are removed, and the result is a re-indexed list.
	 *
	 * @return string[]
	 */
	public static function allowed(): array {
		$default = array( 'post', 'page' );

		/**
		 * Filters the post types that Steadhand's post tools may read and write.
		 *
		 * The default is post and page. The types in PostTypes::ALWAYS_EXCLUDED, every built-in type other than post
		 * and page, and every type registered with map_meta_cap off are removed whatever this filter returns. Values
		 * that are not strings, are not registered post types, or are not sanitize_key() fixed points are dropped.
		 *
		 * @since 0.2.0
		 *
		 * @param string[] $post_types Post type names. Default array( 'post', 'page' ).
		 */
		$filtered = apply_filters( 'steadhand_allowed_post_types', $default );
		if ( ! is_array( $filtered ) ) {
			$filtered = $default;
		}

		$allowed = array();
		foreach ( $filtered as $post_type ) {
			if ( ! is_string( $post_type ) || '' === $post_type || sanitize_key( $post_type ) !== $post_type ) {
				continue;
			}
			if ( ! post_type_exists( $post_type ) || in_array( $post_type, self::ALWAYS_EXCLUDED, true ) || self::is_builtin_other_than_default( $post_type ) ) {
				continue;
			}
			// Core makes none of its per-post checks for a type with map_meta_cap off, and may rename the capability
			// to one the agent holds (for example read). Such a type is never allowed (SPEC §14.1).
			if ( ! self::uses_meta_caps( $post_type ) ) {
				continue;
			}
			$allowed[] = $post_type;
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Whether a post type is built in to WordPress and is not post or page.
	 *
	 * @param string $post_type Post type name, already known to be registered.
	 * @return bool
	 */
	private static function is_builtin_other_than_default( string $post_type ): bool {
		if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return false;
		}

		$object = get_post_type_object( $post_type );

		return null !== $object && true === $object->_builtin;
	}

	/**
	 * Whether a post type is on the allowlist.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function is_allowed( string $post_type ): bool {
		return in_array( $post_type, self::allowed(), true );
	}

	/**
	 * Whether a post type is registered with map_meta_cap on, so that core runs its per-post checks for it. This is the
	 * one definition: allowed() uses it, and so do MetaCaps and SetPostTerms. The test is truthy, as core's own is.
	 *
	 * @param string $post_type Post type name. An unregistered name returns false.
	 * @return bool
	 */
	public static function uses_meta_caps( string $post_type ): bool {
		$object = get_post_type_object( $post_type );

		return $object instanceof \WP_Post_Type && ! empty( $object->map_meta_cap );
	}
}
