<?php
/**
 * Read-only rules about classic menu items: the menu an item belongs to, its parent, depth and subtree, and whether Steadhand hid it.
 * Reads only; every write is in MenuWriter.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * The shared menu rules. The menu tools and the update-menu writer ask these questions, so the answer is the same wherever it is
 * asked. Every database read is uncached, so a change made earlier in the same request is seen.
 *
 * Rows. items_raw() returns one row per item of a classic menu, in menu order, as an array with these keys:
 * - id:     int, the nav_menu_item post ID.
 * - parent: int, the stored _menu_item_menu_item_parent: 0 at top level, the parent's ID otherwise. A stored value that is not a
 *           whole number is -1, which matches no item, so the rule functions treat that item as broken.
 * - order:  int, the stored menu_order. Core never renumbers siblings, so these may have gaps or repeats.
 * - status: string, publish or draft.
 * - post:   \WP_Post, the item.
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
	 * The post meta key core sets on a draft menu item whose menu was deleted (core's _wp_delete_orphaned_draft_menu_items() deletes
	 * such drafts). A draft that carries it is never shown, and never hidden or shown by Steadhand.
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
	 * The items of a classic menu, in menu order, with their stored state. Every item of the menu in publish or draft is returned,
	 * including the draft ones that core's wp_get_nav_menu_items() would skip. That function returns nothing at all when the
	 * stored count of published items is 0, so it cannot see a menu whose items are all drafts. This query does not use it.
	 *
	 * Filters on wp_get_nav_menu_items and pre_wp_setup_nav_menu_item do not run here. Items that are not valid (a trashed or
	 * missing linked object) and orphaned drafts are returned too. A caller decides what to skip. The list is not capped.
	 *
	 * @param \WP_Term $menu A classic menu.
	 * @return array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}>
	 */
	public static function items_raw( \WP_Term $menu ): array {
		$items = get_posts(
			array(
				'post_type'              => 'nav_menu_item',
				'post_status'            => self::STATUSES,
				'nopaging'               => true,
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One menu's items; the term is given by ID.
					array(
						'taxonomy' => 'nav_menu',
						'field'    => 'term_taxonomy_id',
						'terms'    => (int) $menu->term_taxonomy_id,
					),
				),
			)
		);

		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = array(
				'id'     => (int) $item->ID,
				'parent' => self::parent_of( (int) $item->ID ),
				'order'  => (int) $item->menu_order,
				'status' => (string) $item->post_status,
				'post'   => $item,
			);
		}

		return $rows;
	}

	/**
	 * The stored parent of an item: 0 when none is stored, the parent's ID, or -1 when the stored value is not a whole number.
	 *
	 * @param int $item_id Item post ID.
	 */
	private static function parent_of( int $item_id ): int {
		$raw = get_post_meta( $item_id, self::PARENT_META, true );
		if ( '' === $raw ) {
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
	 * @param array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}> $rows      Rows from items_raw().
	 * @param int                                                                                 $parent_id The parent's ID, or 0 for the top level.
	 * @return array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}>
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
	 * @param array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}> $rows Rows from items_raw().
	 * @param int                                                                                 $id   The item's ID.
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
	 * @param array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}> $rows Rows from items_raw().
	 * @param int                                                                                 $id   The subtree's root.
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
	 * @param array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}> $rows          Rows from items_raw().
	 * @param int                                                                                 $item_id       The item that would move.
	 * @param int                                                                                 $new_parent_id The proposed parent, or 0.
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
	 * Whether the item carries core's orphan mark (see ORPHANED_META). Any row counts, so a malformed mark is still a mark.
	 *
	 * @param int $item_id Item post ID.
	 */
	public static function is_orphaned( int $item_id ): bool {
		if ( $item_id < 1 ) {
			return false;
		}

		wp_cache_delete( $item_id, 'post_meta' );

		return metadata_exists( 'post', $item_id, self::ORPHANED_META );
	}

	/**
	 * The rows keyed by item ID. A later row with the same ID replaces an earlier one; items_raw() never repeats an ID.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}> $rows Rows from items_raw().
	 * @return array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}>
	 */
	private static function index( array $rows ): array {
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ $row['id'] ] = $row;
		}

		return $by_id;
	}
}
