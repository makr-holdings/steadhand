<?php
/**
 * Which taxonomies agents may read through the term tools.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomy allowlist for the term tools (SPEC §5, §14.1).
 *
 * A taxonomy is allowed only when all of these hold: it is registered; it is public; it is attached to at least one
 * post type in PostTypes::allowed(); and it is not in ALWAYS_EXCLUDED. There is no filter of its own. The set
 * follows from the allowed post types, so steadhand_allowed_post_types also decides which taxonomies agents may read.
 * Taxonomies attached only to other types, such as link categories or theme taxonomies, are left out.
 */
final class Taxonomies {

	/**
	 * Taxonomies that are never readable through Steadhand, even when they are public and attached to an allowed type.
	 * Menus have their own tools (list-menus and get-menu), and the rest are site-building or internal taxonomies.
	 */
	public const ALWAYS_EXCLUDED = array( 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/**
	 * Returns the taxonomy names agents may read, sorted.
	 *
	 * @return string[]
	 */
	public static function allowed(): array {
		$post_types = PostTypes::allowed();
		$allowed    = array();

		foreach ( get_taxonomies( array(), 'objects' ) as $name => $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy || true !== $taxonomy->public ) {
				continue;
			}

			$name = (string) $name;
			if ( in_array( $name, self::ALWAYS_EXCLUDED, true ) ) {
				continue;
			}

			if ( array() === array_intersect( (array) $taxonomy->object_type, $post_types ) ) {
				continue;
			}

			$allowed[] = $name;
		}

		sort( $allowed, SORT_STRING );

		return $allowed;
	}

	/**
	 * Whether a taxonomy is on the allowlist.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public static function is_allowed( string $taxonomy ): bool {
		return in_array( $taxonomy, self::allowed(), true );
	}
}
