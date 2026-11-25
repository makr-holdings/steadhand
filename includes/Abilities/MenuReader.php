<?php
/**
 * Shared code for the menu tools: the two kinds of menu, their items, their locations and their schemas.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\Pipeline\MenuRules;

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
	 * The items of a classic menu, in menu order, for get-menu: the published items and the draft ones, which the public site does not
	 * print. Returns the items and whether the menu has more than MAX_ITEMS of them.
	 *
	 * Items core would not print are left out: a trashed or missing linked object (an invalid item), and an orphaned draft
	 * (MenuRules::ORPHANED_META). Each kept item is set up the way core sets up a menu item (wp_setup_nav_menu_item(), with the
	 * pre_wp_setup_nav_menu_item filter), so its title and URL are the ones the public menu prints, for a draft too. The menu_order
	 * of the kept items is renumbered 1, 2, 3 as wp_get_nav_menu_items() does, before the wp_get_nav_menu_items filter runs.
	 *
	 * The wp_get_nav_menu_items filter then runs on the kept items, as core runs it, and the result is trusted only in part: a
	 * returned entry is kept only when it is a WP_Post of a nav_menu_item whose ID was in the list passed in, and only once per ID.
	 * So a filter may drop an item or change its title, URL, parent or order, but cannot add one. A non-array result means no items.
	 *
	 * Setup stops after MAX_ITEMS + 1 kept items, one more than the reader returns, so truncated stays right without loading an
	 * unbounded menu. This does not call wp_get_nav_menu_items(): that function returns nothing when the menu's stored item count is 0,
	 * and the stored count counts only published items, so a menu whose items are all hidden would look empty.
	 *
	 * @param \WP_Term $menu Classic menu.
	 * @return array{items: \WP_Post[], truncated: bool}
	 */
	public static function page( \WP_Term $menu ): array {
		$kept = array();
		foreach ( MenuRules::items_raw( $menu ) as $row ) {
			if ( $row['orphaned'] ) {
				continue;
			}

			// A copy, so the menu_order change below never reaches the post object that get_posts() caches.
			$item = wp_setup_nav_menu_item( clone $row['post'] );
			if ( ! $item instanceof \WP_Post || ! empty( $item->_invalid ) ) {
				continue;
			}

			$kept[] = $item;
			if ( count( $kept ) > self::MAX_ITEMS ) {
				break;
			}
		}

		$truncated = count( $kept ) > self::MAX_ITEMS;

		$position = 1;
		foreach ( $kept as $item ) {
			$item->menu_order = $position++;
		}

		$allowed = array();
		foreach ( $kept as $item ) {
			$allowed[ (int) $item->ID ] = true;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, run as core runs it.
		$filtered = apply_filters( 'wp_get_nav_menu_items', $kept, $menu, MenuRules::query_args( $menu ) );
		if ( ! is_array( $filtered ) ) {
			return array(
				'items'     => array(),
				'truncated' => $truncated,
			);
		}

		$items = array();
		$seen  = array();
		foreach ( $filtered as $entry ) {
			if ( ! $entry instanceof \WP_Post || 'nav_menu_item' !== $entry->post_type ) {
				continue;
			}

			$id = (int) $entry->ID;
			if ( ! isset( $allowed[ $id ] ) || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$items[]     = $entry;
		}

		if ( count( $items ) > self::MAX_ITEMS ) {
			$truncated = true;
		}

		return array(
			'items'     => $items,
			'truncated' => $truncated,
		);
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
	 * linked object is read. hidden is true for a draft item, which the public site does not print, and false for a
	 * published one.
	 *
	 * @param \WP_Post $item Valid item from page().
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
			// The stored parent, parsed as MenuRules does. A value that is not a whole number is shown as 0 (top level), so get-menu
			// never returns a negative parent. The item itself is still listed.
			'parent'      => max( 0, MenuRules::parse_parent( $item->menu_item_parent ?? '' ) ),
			'order'       => (int) $item->menu_order,
			'target'      => PostReader::utf8( (string) ( $item->target ?? '' ) ),
			'classes'     => self::classes( $item->classes ?? array() ),
			'description' => TermReader::description( (string) ( $item->description ?? '' ) ),
			'hidden'      => 'draft' === (string) $item->post_status,
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
			'hidden'      => array( 'type' => 'boolean' ),
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
