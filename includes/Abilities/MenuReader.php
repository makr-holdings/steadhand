<?php
/**
 * Shared code for the menu tools: the two kinds of menu, their items, their locations and their schemas.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Menus come in two kinds, and both are readable through the menu tools only (SPEC §5):
 *
 * - Classic menus are terms in the nav_menu taxonomy. Their items are nav_menu_item posts. Themes assign them to
 *   locations.
 * - Block navigation is wp_navigation posts. Block themes use them.
 *
 * The post and term tools never read these types, because PostTypes and Taxonomies exclude them. The menu tools do
 * their own checks, which are these functions. They do not call current_user_can( 'read_post' ) on a nav_menu_item
 * or wp_navigation post. MetaCaps denies read_post for both types on purpose, because they are not allowed post types,
 * so such a call would always refuse. Do not "fix" that by adding a read_post check here. The gate is steadhand_read,
 * which the pipeline checks, plus the status and type checks below.
 */
final class MenuReader {

	/**
	 * Most menus list-menus returns, classic and block together. Classic menus come first.
	 */
	public const MAX_MENUS = 200;

	/**
	 * Most items get-menu returns for one classic menu.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * Longest classic menu item title returned, in bytes. Longer titles are cut.
	 */
	public const MAX_TITLE_BYTES = 1000;

	/**
	 * The statuses a block navigation post may have to be read at all. list-menus lists exactly these, and get-menu reads
	 * exactly these; trash and auto-draft are neither listed nor readable. One constant, so the two tools cannot drift.
	 */
	public const BLOCK_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * Returns a classic menu, or null. The term must be in nav_menu and must have the requested ID.
	 *
	 * Because wp_get_nav_menu_object() also looks an ID up as a slug or a name, a numeric slug could return a different
	 * menu. The ID check rules that out.
	 *
	 * @param int $id Term ID.
	 */
	public static function classic_menu( int $id ): ?\WP_Term {
		$term = wp_get_nav_menu_object( $id );
		if ( ! $term instanceof \WP_Term || 'nav_menu' !== $term->taxonomy || (int) $term->term_id !== $id ) {
			return null;
		}

		return $term;
	}

	/**
	 * Returns a block navigation post, or null. It must be a wp_navigation post in one of BLOCK_STATUSES.
	 *
	 * @param int $id Post ID.
	 */
	public static function block_navigation( int $id ): ?\WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wp_navigation' !== $post->post_type || ! in_array( $post->post_status, self::BLOCK_STATUSES, true ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * The items of a classic menu in menu order, without items core marks invalid (a trashed or missing linked object).
	 *
	 * Core removes invalid items outside wp-admin (_is_valid_nav_menu_item() in wp_get_nav_menu_items()), and Steadhand
	 * calls run there. This check is belt-and-braces, for a call made with is_admin() true. No test makes such a call,
	 * so it is not covered by one.
	 *
	 * @param \WP_Term $menu Classic menu.
	 * @return \WP_Post[]
	 */
	public static function items( \WP_Term $menu ): array {
		$items = wp_get_nav_menu_items( $menu, array( 'update_post_term_cache' => false ) );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$valid = array();
		foreach ( $items as $item ) {
			if ( $item instanceof \WP_Post && empty( $item->_invalid ) ) {
				$valid[] = $item;
			}
		}

		return array_values( $valid );
	}

	/**
	 * The theme locations a classic menu is assigned to.
	 *
	 * @param int                  $menu_id   Menu term ID.
	 * @param array<string, mixed> $locations Location slug to menu term ID, as get_nav_menu_locations() returns it.
	 * @return string[]
	 */
	public static function locations_for( int $menu_id, array $locations ): array {
		$found = array();
		foreach ( $locations as $location => $assigned ) {
			if ( (int) $assigned === $menu_id ) {
				$found[] = PostReader::utf8( (string) $location );
			}
		}

		return $found;
	}

	/**
	 * One classic menu item, in the get-menu shape. Title and URL are what the menu shows: for an item linked to an
	 * object, core derives them from that object, which is also what the front end prints. Nothing else about the
	 * linked object is read.
	 *
	 * @param \WP_Post $item Valid item from items().
	 * @return array<string, mixed>
	 */
	public static function item( \WP_Post $item ): array {
		$type = (string) ( $item->type ?? '' );

		return array(
			'id'          => (int) $item->ID,
			'title'       => PostReader::label_text( (string) ( $item->title ?? '' ), self::MAX_TITLE_BYTES ),
			'url'         => PostReader::utf8( (string) ( $item->url ?? '' ) ),
			'type'        => PostReader::utf8( $type ),
			// Core stores 'custom' and the item's own ID for a custom link. Agents get '' and 0, as the tool describes.
			'object'      => 'custom' === $type ? '' : PostReader::utf8( (string) ( $item->object ?? '' ) ),
			'object_id'   => 'custom' === $type ? 0 : (int) ( $item->object_id ?? 0 ),
			'parent'      => (int) ( $item->menu_item_parent ?? 0 ),
			'order'       => (int) $item->menu_order,
			'target'      => PostReader::utf8( (string) ( $item->target ?? '' ) ),
			'classes'     => self::classes( $item->classes ?? array() ),
			'description' => TermReader::description( (string) ( $item->description ?? '' ) ),
		);
	}

	/**
	 * The CSS classes of an item, as plain strings.
	 *
	 * @param mixed $classes Value stored on the item.
	 * @return string[]
	 */
	private static function classes( mixed $classes ): array {
		if ( ! is_array( $classes ) ) {
			return array();
		}

		$out = array();
		foreach ( $classes as $class ) {
			if ( is_string( $class ) && '' !== $class ) {
				$out[] = PostReader::utf8( $class );
			}
		}

		return $out;
	}

	/**
	 * Schema for one classic menu item.
	 *
	 * @return array<string, mixed>
	 */
	public static function item_schema(): array {
		$properties = array(
			'id'          => PostReader::id_schema(),
			'title'       => array( 'type' => 'string' ),
			'url'         => array( 'type' => 'string' ),
			'type'        => array( 'type' => 'string' ),
			'object'      => array( 'type' => 'string' ),
			'object_id'   => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'parent'      => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'order'       => array( 'type' => 'integer' ),
			'target'      => array( 'type' => 'string' ),
			'classes'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'description' => array( 'type' => 'string' ),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( $properties ),
			'properties'           => $properties,
		);
	}

	/**
	 * A query for block navigation posts that list-menus shows: publish and draft, by title.
	 *
	 * @param int $limit Most posts to return. At least 1.
	 */
	public static function block_navigation_list( int $limit ): \WP_Query {
		return new \WP_Query(
			array(
				'post_type'              => 'wp_navigation',
				'post_status'            => self::BLOCK_STATUSES,
				'posts_per_page'         => max( 1, $limit ),
				'orderby'                => array(
					'title' => 'ASC',
					'ID'    => 'ASC',
				),
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}
}
