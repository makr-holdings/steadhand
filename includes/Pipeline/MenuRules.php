<?php
/**
 * Read-only rules about classic menu items: the menu an item belongs to, its parent, depth and subtree, and whether Steadhand hid it.
 * Reads only. The update-menu writer, which will ask these questions too, comes in the next step and is not in this file.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * The shared menu rules: which items a classic menu has, and how they nest. MenuReader uses them now; the update-menu writer
 * is the next step. Reads go through core's caches. items_raw() primes the post meta and the linked objects of a whole menu in one
 * go, and the rules that take a row read nothing from the database. hidden_by_steadhand() is the one exception: it reads the
 * marker through TrashRules::value(), which bypasses the cache, so a marker written earlier in the same request is seen.
 *
 * Rows. items_raw() returns one row per item of a classic menu, in menu order, as an array with these keys:
 * - id:       int, the nav_menu_item post ID.
 * - parent:   int, the stored _menu_item_menu_item_parent, read by parse_parent(): 0 at top level, the parent's ID otherwise.
 *             A stored value that is not a whole number is -1, which matches no item, so the rule functions treat that item
 *             as broken.
 * - order:    int, the stored menu_order. Core never renumbers siblings, so these may have gaps or repeats.
 * - status:   string, publish or draft.
 * - orphaned: bool, whether the item carries core's orphan mark (ORPHANED_META).
 * - post:     \WP_Post, the item.
 *
 * The functions that take rows (children(), depth(), subtree_height(), would_cycle()) never read the database. They are
 * safe on malformed data: a parent that is missing, belongs to another menu or forms a cycle never loops, and the functions
 * say so with null or true, which the writer refuses.
 */
final class MenuRules {

	/**
	 * Most items one classic menu may hold for a write. A menu with more is refused as too large. Items_raw() returns every item,
	 * so a caller compares count() with this constant.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * Most levels a menu may have, counting the top level as 1.
	 */
	public const MAX_DEPTH = 4;

	/**
	 * Most rows one move may change.
	 */
	public const MAX_MOVE_ROWS = 50;

	/**
	 * The post meta key that marks a classic menu item Steadhand hid. Its value is the ID of the agent user that hid it.
	 */
	public const HIDDEN_META = '_steadhand_menu_hidden';

	/**
	 * The post meta key core sets on an item saved with menu ID 0, that is, a draft menu item not yet saved to a menu. Core's
	 * _wp_delete_orphaned_draft_menu_items() deletes such drafts later. A draft that carries it is never shown, and Steadhand
	 * never hides or shows it.
	 */
	public const ORPHANED_META = '_menu_item_orphaned';

	/**
	 * The statuses a classic menu item may have to be read at all, and the only ones the writer changes.
	 */
	private const STATUSES = array( 'publish', 'draft' );

	/**
	 * The meta key that stores an item's parent, as core's wp_setup_nav_menu_item() reads it.
	 */
	private const PARENT_META = '_menu_item_menu_item_parent';

	/**
	 * A string of digits: a whole number as WordPress stores it in meta.
	 */
	private const DIGITS = '/^\d+\z/';

	/**
	 * A user ID: digits that do not start with 0.
	 */
	private const USER_ID = '/^[1-9]\d*\z/';

	/**
	 * The query for a classic menu's items, in core's shape: the arguments wp_get_nav_menu_items() builds, with the statuses named
	 * explicitly. The reader passes the same array to the wp_get_nav_menu_items filter, so a filter sees what the query asked for.
	 *
	 * @param \WP_Term $menu A classic menu.
	 * @return array<string, mixed>
	 */
	public static function query_args( \WP_Term $menu ): array {
		return array(
			'order'                  => 'ASC',
			'orderby'                => array(
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			),
			'post_type'              => 'nav_menu_item',
			'post_status'            => self::STATUSES,
			'output'                 => ARRAY_A,
			'output_key'             => 'menu_order',
			'nopaging'               => true,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true,
			'update_menu_item_cache' => true,
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One menu's items; the term is given by ID.
				array(
					'taxonomy' => 'nav_menu',
					'field'    => 'term_taxonomy_id',
					'terms'    => (int) $menu->term_taxonomy_id,
				),
			),
		);
	}

	/**
	 * The items of a classic menu in menu order, with their stored state. Every item of the menu in publish or draft is returned,
	 * including the draft ones that core's wp_get_nav_menu_items() would skip. That function returns nothing at all when the
	 * stored count of published items is 0, so it cannot see a menu whose items are all drafts. This query does not use it.
	 *
	 * The query primes the linked posts and terms of the whole menu (update_menu_item_cache) and the items' meta
	 * (update_post_meta_cache), so the rows that follow cost no query each. Filters on wp_get_nav_menu_items and pre_wp_setup_nav_menu_item do not run
	 * here. Items that are not valid (a trashed or missing linked object) and orphaned drafts are returned too; a caller decides what
	 * to skip. The list is not capped: the writer compares count() with MAX_ITEMS, and the reader stops early (see MenuReader::page()).
	 *
	 * @param \WP_Term $menu A classic menu.
	 * @return array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}>
	 */
	public static function items_raw( \WP_Term $menu ): array {
		// get_posts() runs the query with its query filters off unless asked otherwise, as core's own menu read does. The
		// rules need every stored row of the menu, so a plugin's query filter must not hide one.
		$items = get_posts( self::query_args( $menu ) );

		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = array(
				'id'       => (int) $item->ID,
				'parent'   => self::parent_of( (int) $item->ID ),
				'order'    => (int) $item->menu_order,
				'status'   => (string) $item->post_status,
				'orphaned' => self::is_orphaned( (int) $item->ID ),
				'post'     => $item,
			);
		}

		return $rows;
	}

	/**
	 * The stored parent of an item, read by parse_parent().
	 *
	 * @param int $item_id Item post ID.
	 */
	private static function parent_of( int $item_id ): int {
		return self::parse_parent( get_post_meta( $item_id, self::PARENT_META, true ) );
	}

	/**
	 * The parent ID that a stored parent value means: 0 for an empty value (top level), the number for a value that is only digits,
	 * and -1 for anything else (a negative number, a number followed by text, an array). -1 matches no item.
	 *
	 * @param mixed $raw The stored value, as get_post_meta() or a menu item's menu_item_parent property holds it.
	 */
	public static function parse_parent( mixed $raw ): int {
		if ( null === $raw || '' === $raw ) {
			return 0;
		}

		if ( ! is_scalar( $raw ) ) {
			return -1;
		}

		$raw = (string) $raw;

		return 1 === preg_match( self::DIGITS, $raw ) ? (int) $raw : -1;
	}

	/**
	 * The one classic menu an item belongs to, or null. Null when the post is not a nav_menu_item, when it belongs to no menu, and
	 * when it belongs to more than one.
	 *
	 * @param \WP_Post $item The item.
	 */
	public static function menu_of( \WP_Post $item ): ?\WP_Term {
		if ( 'nav_menu_item' !== $item->post_type ) {
			return null;
		}

		$terms = wp_get_object_terms( (int) $item->ID, 'nav_menu', array( 'update_term_meta_cache' => false ) );
		if ( is_wp_error( $terms ) || 1 !== count( $terms ) ) {
			return null;
		}

		$term = reset( $terms );

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * The direct children of an item, in the order of the rows.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows      Rows from items_raw().
	 * @param int                                                                                                 $parent_id The parent's ID, or 0 for the top level.
	 * @return array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}>
	 */
	public static function children( array $rows, int $parent_id ): array {
		$found = array();
		foreach ( $rows as $row ) {
			if ( $row['parent'] === $parent_id ) {
				$found[] = $row;
			}
		}

		return $found;
	}

	/**
	 * The depth of an item: how many items lie on the path from it up to the top level, the item itself included. A top-level
	 * item has depth 1.
	 *
	 * Null when the item is not in the rows, when its parent chain reaches an ID that is not in the rows (a missing item, one of
	 * another menu, or a malformed parent), when it loops, or when the chain is longer than MAX_ITEMS.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows from items_raw().
	 * @param int                                                                                                 $id   The item's ID.
	 */
	public static function depth( array $rows, int $id ): ?int {
		$by_id   = self::index( $rows );
		$seen    = array();
		$levels  = 0;
		$current = $id;

		while ( true ) {
			if ( ! isset( $by_id[ $current ] ) || isset( $seen[ $current ] ) || $levels >= self::MAX_ITEMS ) {
				return null;
			}

			$seen[ $current ] = true;
			++$levels;

			$parent = $by_id[ $current ]['parent'];
			if ( 0 === $parent ) {
				return $levels;
			}

			$current = $parent;
		}
	}

	/**
	 * The number of levels in the subtree that starts at an item, the item itself included: 1 for an item with no children.
	 *
	 * Null when the item is not in the rows, when the subtree reaches an item twice (a loop), or when it has more than MAX_ITEMS
	 * items.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows from items_raw().
	 * @param int                                                                                                 $id   The subtree's root.
	 */
	public static function subtree_height( array $rows, int $id ): ?int {
		if ( ! isset( self::index( $rows )[ $id ] ) ) {
			return null;
		}

		$height = 0;
		$count  = 0;
		$seen   = array();
		$stack  = array( array( $id, 1 ) );

		while ( array() !== $stack ) {
			list( $current, $level ) = array_pop( $stack );

			++$count;
			if ( isset( $seen[ $current ] ) || $count > self::MAX_ITEMS ) {
				return null;
			}

			$seen[ $current ] = true;
			$height           = max( $height, $level );

			foreach ( self::children( $rows, $current ) as $child ) {
				$stack[] = array( $child['id'], $level + 1 );
			}
		}

		return $height;
	}

	/**
	 * Whether making $new_parent_id the parent of $item_id would make the item its own ancestor.
	 *
	 * False when $new_parent_id is 0 (the top level), and when the new parent is not in the rows or its chain of parents does not
	 * reach the item. True when the new parent is the item itself, when the item is an ancestor of the new parent, and when the
	 * walk up from the new parent does not finish within MAX_ITEMS steps (a loop elsewhere in the menu). A refusal is the safe
	 * answer to every one of these.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows          Rows from items_raw().
	 * @param int                                                                                                 $item_id       The item that would move.
	 * @param int                                                                                                 $new_parent_id The proposed parent, or 0.
	 */
	public static function would_cycle( array $rows, int $item_id, int $new_parent_id ): bool {
		if ( 0 === $new_parent_id ) {
			return false;
		}

		if ( $new_parent_id === $item_id ) {
			return true;
		}

		$by_id   = self::index( $rows );
		$current = $new_parent_id;

		for ( $steps = 0; $steps <= self::MAX_ITEMS; ++$steps ) {
			if ( $current === $item_id ) {
				return true;
			}

			if ( ! isset( $by_id[ $current ] ) ) {
				return false;
			}

			$current = $by_id[ $current ]['parent'];
			if ( 0 === $current ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether Steadhand hid the item: the hidden marker has exactly one row and its value is a user ID. Any other count, or a value
	 * that is not digits, is false.
	 *
	 * @param int $item_id Item post ID.
	 */
	public static function hidden_by_steadhand( int $item_id ): bool {
		$value = TrashRules::value( $item_id, self::HIDDEN_META );

		return null !== $value && 1 === preg_match( self::USER_ID, $value );
	}

	/**
	 * Whether the item carries core's orphan mark (see ORPHANED_META). Any row counts, so a malformed mark is still a mark. Reads
	 * through the post meta cache, so after items_raw() it costs no query.
	 *
	 * @param int $item_id Item post ID.
	 */
	public static function is_orphaned( int $item_id ): bool {
		if ( $item_id < 1 ) {
			return false;
		}

		return metadata_exists( 'post', $item_id, self::ORPHANED_META );
	}

	/**
	 * The rows keyed by item ID. A later row with the same ID replaces an earlier one; items_raw() never repeats an ID.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows from items_raw().
	 * @return array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}>
	 */
	private static function index( array $rows ): array {
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ $row['id'] ] = $row;
		}

		return $by_id;
	}
}
