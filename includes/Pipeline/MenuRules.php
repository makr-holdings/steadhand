<?php
/**
 * Read-only rules about classic menu items: the menu an item belongs to, its parent, depth and subtree, and whether Steadhand hid it.
 * Reads only. The update-menu writer (MenuWriter) asks the same questions, through fresh_state() and items_raw(); it makes every change.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * The shared menu rules: which items a classic menu has, and how they nest. MenuReader and the update-menu ability and writer use
 * them. Reads go through core's caches, except fresh_state() and hide_recorded(), which read without the cache. items_raw() primes the post meta and the linked objects of a whole menu in one
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
	 * The post meta key that holds the ID of the item a hide left hidden. show checks that it is the item's own ID.
	 */
	public const HIDDEN_ITEM_META = '_steadhand_menu_hidden_item';

	/**
	 * The post meta key that holds the item's post_modified_gmt as the hide left it. show checks that the item still has that
	 * time, so an edit after the hide (by anyone other than a Steadhand rename, which refreshes it) is not undone by show.
	 */
	public const HIDDEN_MODIFIED_META = '_steadhand_menu_hidden_modified';

	/**
	 * Every post meta key Steadhand stores on a classic menu item. They are removed when the item is published by any route,
	 * at the start of every hide, and at uninstall.
	 *
	 * @var string[]
	 */
	public const HIDDEN_KEYS = array(
		self::HIDDEN_META,
		self::HIDDEN_ITEM_META,
		self::HIDDEN_MODIFIED_META,
	);

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
	 * The meta key that stores an item's parent, as core's wp_setup_nav_menu_item() reads it. Public so the writer stores it the way it is read.
	 */
	public const PARENT_META = '_menu_item_menu_item_parent';

	/**
	 * The refusal codes plan_move() returns. Each is the code the call gets, so the ability and the writer map them one to one.
	 */
	public const MOVE_INVALID     = 'steadhand_invalid_input';
	public const MOVE_UNSUPPORTED = 'steadhand_refused_unsupported_item';
	public const MOVE_HIDDEN      = 'steadhand_refused_hidden_parent';
	public const MOVE_DEPTH       = 'steadhand_refused_menu_depth';
	public const MOVE_ORDER       = 'steadhand_refused_menu_order';
	public const MOVE_TOO_LARGE   = 'steadhand_refused_move_too_large';

	/**
	 * The refusal for a menu over MAX_ITEMS, which a move checks before any other planning work. The same code and status as the ability's own check.
	 */
	public const MOVE_MENU_TOO_LARGE = 'steadhand_refused_menu_too_large';

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

		$children = self::child_map( $rows );
		$height   = 0;
		$count    = 0;
		$seen     = array();
		$stack    = array( array( $id, 1 ) );

		while ( array() !== $stack ) {
			list( $current, $level ) = array_pop( $stack );

			++$count;
			if ( isset( $seen[ $current ] ) || $count > self::MAX_ITEMS ) {
				return null;
			}

			$seen[ $current ] = true;
			$height           = max( $height, $level );

			foreach ( $children[ $current ] ?? array() as $child ) {
				$stack[] = array( $child, $level + 1 );
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
	 * Whether the three keys a hide stores are all there, each with exactly one row, and say what the hide left: the hidden-by
	 * key is a user ID, the item-ID key is this item's own ID, and the modified key is the item's current post_modified_gmt.
	 * Every read is uncached. show needs this as well as the draft status. A direct database write clears no key, and it changes the
	 * modified time only when the writer sets it, so the modified key catches an edit made through WordPress's save functions, not
	 * every direct write.
	 *
	 * @param int $item_id Item post ID.
	 */
	public static function hide_recorded( int $item_id ): bool {
		if ( $item_id < 1 ) {
			return false;
		}

		$user     = TrashRules::value( $item_id, self::HIDDEN_META );
		$owner    = TrashRules::value( $item_id, self::HIDDEN_ITEM_META );
		$modified = TrashRules::value( $item_id, self::HIDDEN_MODIFIED_META );

		if ( null === $user || null === $owner || null === $modified ) {
			return false;
		}

		if ( 1 !== preg_match( self::USER_ID, $user ) || $owner !== (string) $item_id || '' === $modified ) {
			return false;
		}

		return TrashRules::current_modified_gmt( $item_id ) === $modified;
	}

	/**
	 * One item read with no cache in the way: the post, its menu, its parent and its order, and whether it carries the orphan
	 * mark. Used by the writer to check the state it is about to change, and by the ability for the state it reports.
	 *
	 * The keys: id, menu (the classic menu's term ID, or 0 when the item belongs to no menu or to more than one), status, title (the
	 * stored title, raw), parent (parse_parent() of the stored value), order (menu_order), orphaned (bool), post (the WP_Post).
	 * Null when the ID is not a nav_menu_item.
	 *
	 * @param int $item_id Item post ID.
	 * @return array{id: int, menu: int, status: string, title: string, parent: int, order: int, orphaned: bool, post: \WP_Post}|null
	 */
	public static function fresh_state( int $item_id ): ?array {
		if ( $item_id < 1 ) {
			return null;
		}

		clean_post_cache( $item_id );
		clean_object_term_cache( array( $item_id ), 'nav_menu_item' );
		wp_cache_delete( $item_id, 'post_meta' );

		$item = get_post( $item_id );
		if ( ! $item instanceof \WP_Post || 'nav_menu_item' !== $item->post_type ) {
			return null;
		}

		$menu = self::menu_of( $item );
		wp_cache_delete( $item_id, 'post_meta' );

		return array(
			'id'       => $item_id,
			'menu'     => null === $menu ? 0 : (int) $menu->term_id,
			'status'   => (string) $item->post_status,
			'title'    => (string) $item->post_title,
			'parent'   => self::parent_of( $item_id ),
			'order'    => (int) $item->menu_order,
			'orphaned' => self::is_orphaned( $item_id ),
			'post'     => $item,
		);
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
	 * The IDs of an item and all its descendants, found from the parent links and never from the order. Returned in the order of the rows.
	 * The walk visits each ID once, so a loop in the data ends the walk; the result is then the set reached. An ID that is not in the
	 * rows gives an empty list.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows from items_raw().
	 * @param int                                                                                                 $id   The subtree's root.
	 * @return int[]
	 */
	public static function subtree_ids( array $rows, int $id ): array {
		if ( ! isset( self::index( $rows )[ $id ] ) ) {
			return array();
		}

		$children = self::child_map( $rows );
		$found    = array( $id => true );
		$queue    = array( $id );
		while ( array() !== $queue ) {
			$current = array_shift( $queue );
			foreach ( $children[ $current ] ?? array() as $child ) {
				if ( ! isset( $found[ $child ] ) ) {
					$found[ $child ] = true;
					$queue[]         = $child;
				}
			}
		}

		$ids = array();
		foreach ( $rows as $row ) {
			if ( isset( $found[ $row['id'] ] ) ) {
				$ids[] = $row['id'];
			}
		}

		return $ids;
	}

	/**
	 * Whether the stored order is consistent with the tree: every item comes after its parent (when the parent is in the rows), and the
	 * items of every subtree are next to each other in menu order. A menu saved in Appearance > Menus normally leaves both true, but
	 * other tools and imports can store an order that does not, and a menu that does not is refused by a move, because the target
	 * order cannot be written safely over it.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows from items_raw().
	 */
	public static function consistent_with_tree( array $rows ): bool {
		$position = array();
		foreach ( $rows as $index => $row ) {
			$position[ $row['id'] ] = $index;
		}

		foreach ( $rows as $row ) {
			if ( 0 !== $row['parent'] && isset( $position[ $row['parent'] ] ) && $position[ $row['parent'] ] > $position[ $row['id'] ] ) {
				return false;
			}
		}

		// One pass from the roots: every subtree's lowest and highest position and its size are gathered from its children, so a
		// subtree is contiguous when its span holds exactly its members. A row that no root reaches (a loop) makes the order inconsistent.
		$children = self::child_map( $rows );
		$roots    = array();
		foreach ( $rows as $row ) {
			if ( 0 === $row['parent'] || ! isset( $position[ $row['parent'] ] ) ) {
				$roots[] = $row['id'];
			}
		}

		$low     = array();
		$high    = array();
		$size    = array();
		$visited = array();
		$stack   = array();
		foreach ( array_reverse( $roots ) as $root ) {
			$stack[] = array( $root, false );
		}

		while ( array() !== $stack ) {
			list( $id, $done ) = array_pop( $stack );

			if ( $done ) {
				$lo = $position[ $id ];
				$hi = $lo;
				$n  = 1;
				foreach ( $children[ $id ] ?? array() as $child ) {
					$lo = min( $lo, $low[ $child ] );
					$hi = max( $hi, $high[ $child ] );
					$n += $size[ $child ];
				}

				if ( $hi - $lo + 1 !== $n ) {
					return false;
				}

				$low[ $id ]  = $lo;
				$high[ $id ] = $hi;
				$size[ $id ] = $n;
				continue;
			}

			if ( isset( $visited[ $id ] ) ) {
				return false;
			}

			$visited[ $id ] = true;
			$stack[]        = array( $id, true );
			foreach ( $children[ $id ] ?? array() as $child ) {
				if ( isset( $visited[ $child ] ) ) {
					return false;
				}

				$stack[] = array( $child, false );
			}
		}

		return count( $visited ) === count( $rows );
	}

	/**
	 * The flat order of the whole menu after a move. The item's subtree is taken out of the current flat order (menu_order, then ID) and
	 * put back as one block, in its own current order: directly after the subtree of $after_id when $after_id is not 0; directly after
	 * $parent_id itself when $after_id is 0 and $parent_id is not 0 (so the item is the first child); and at the very start when both are 0.
	 *
	 * The caller has checked the move (see plan_move()): $after_id, when not 0, is a child of $parent_id outside the item's subtree, and
	 * $parent_id is not in the subtree. A parent or after that is not in the rows is not placed.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows      Rows from items_raw().
	 * @param int                                                                                                 $item_id   The item that moves.
	 * @param int                                                                                                 $parent_id The new parent, or 0.
	 * @param int                                                                                                 $after_id  The new predecessor among the new siblings, or 0 for first.
	 * @return int[] Every ID of the menu, in the new flat order.
	 */
	public static function target_order( array $rows, int $item_id, int $parent_id, int $after_id ): array {
		$in_block = array_flip( self::subtree_ids( $rows, $item_id ) );
		$parents  = array();
		$block    = array();
		$rest     = array();
		foreach ( $rows as $row ) {
			$parents[ $row['id'] ] = $row['parent'];
			if ( isset( $in_block[ $row['id'] ] ) ) {
				$block[] = $row['id'];
			} else {
				$rest[] = $row['id'];
			}
		}

		$insert = 0;
		if ( 0 !== $after_id ) {
			$in_after = array_flip( self::subtree_ids( $rows, $after_id ) );
			foreach ( $rest as $index => $id ) {
				if ( isset( $in_after[ $id ] ) ) {
					$insert = $index + 1;
				}
			}
		} elseif ( 0 !== $parent_id ) {
			$index  = array_search( $parent_id, $rest, true );
			$insert = false === $index ? 0 : $index + 1;
		} else {
			// First at the top level: directly before the first top-level item, so an item that dangles (its parent is not in the menu)
			// stays where it was. With no top-level item left, the block goes to the start.
			foreach ( $rest as $index => $id ) {
				if ( 0 === $parents[ $id ] ) {
					$insert = $index;
					break;
				}
			}
		}

		return array_merge( array_slice( $rest, 0, $insert ), $block, array_slice( $rest, $insert ) );
	}

	/**
	 * The new menu_order values a move writes, for a target order from target_order(). Only the span from the first to the last position
	 * that differs is rewritten, and each position takes the order value it had before, so no value is invented and the items outside the
	 * span keep theirs. The values of the span must be positive and strictly increasing, and the values either side of it must be strictly
	 * smaller and larger; otherwise the new order would be ambiguous and the result is null.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows   Rows from items_raw().
	 * @param int[]                                                                                               $target The target flat order.
	 * @return array<int, int>|null The new order of each row that changes, keyed by ID (empty when nothing changes), or null.
	 */
	public static function order_changes( array $rows, array $target ): ?array {
		$flat   = array();
		$orders = array();
		foreach ( $rows as $row ) {
			$flat[]   = $row['id'];
			$orders[] = $row['order'];
		}

		$count = count( $flat );
		$low   = null;
		$high  = null;
		for ( $index = 0; $index < $count; ++$index ) {
			if ( $target[ $index ] !== $flat[ $index ] ) {
				$low  = $low ?? $index;
				$high = $index;
			}
		}

		if ( null === $low || null === $high ) {
			return array();
		}

		if ( ( $low > 0 && $orders[ $low - 1 ] >= $orders[ $low ] ) || ( $high + 1 < $count && $orders[ $high + 1 ] <= $orders[ $high ] ) ) {
			return null;
		}

		if ( ! self::ascending_positive( $orders, $low, $high ) ) {
			return null;
		}

		$changes = array();
		for ( $index = $low; $index <= $high; ++$index ) {
			if ( $target[ $index ] !== $flat[ $index ] ) {
				$changes[ $target[ $index ] ] = $orders[ $index ];
			}
		}

		return $changes;
	}

	/**
	 * Whether the order values from $low to $high, in row order, are all positive integers that strictly increase: each is at least 1,
	 * and each is greater than the one before it inside the range. The first value of the range is only checked for being positive.
	 *
	 * This is the check order_changes() makes on the span a move rewrites. movable() makes it on the whole menu.
	 *
	 * @param int[] $orders The stored order of each row, in row order.
	 * @param int   $low    The first index.
	 * @param int   $high   The last index. An empty range (high below low) is true.
	 */
	private static function ascending_positive( array $orders, int $low, int $high ): bool {
		for ( $index = $low; $index <= $high; ++$index ) {
			if ( $orders[ $index ] < 1 || ( $index > $low && $orders[ $index ] <= $orders[ $index - 1 ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a classic menu's stored order is one a move can rewrite, which is what get-menu reports as movable. True when the order is
	 * consistent with the tree (consistent_with_tree()), every parent is a row of the menu or the top level (every_parent_listed()), and
	 * every row's order is a positive integer that is greater than the row before it (ascending_positive() over the whole menu).
	 *
	 * The planner, plan_move(), refuses a move with MOVE_ORDER when the tree check fails (every move), when order_changes() finds the
	 * span a move rewrites not positive and increasing, or a boundary out of order, and when inverse_restores() finds that the inverse
	 * would not put the menu back. The span check is applied here to the whole menu, which is stricter, and the parent check covers the
	 * inverse case. So when this is true, no move is refused with MOVE_ORDER. The menu's size, depth, hidden parent, edit rights and the
	 * move's own target can still refuse a move for other reasons. When this is false, a move may be refused with MOVE_ORDER until the
	 * menu is saved in Appearance > Menus, and a false menu can also accept every move.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows from items_raw().
	 */
	public static function movable( array $rows ): bool {
		return self::consistent_with_tree( $rows )
			&& self::every_parent_listed( $rows )
			&& self::ascending_positive( array_column( $rows, 'order' ), 0, count( $rows ) - 1 );
	}

	/**
	 * Whether every row's parent is the top level (0) or an item of the menu. A row whose parent is missing, or malformed, is a root
	 * to consistent_with_tree(), but its place can be the one an inverse move cannot restore (a row at the start that is not top
	 * level), so movable() counts it as not movable. Read-only; the rows are not changed.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows from items_raw().
	 */
	private static function every_parent_listed( array $rows ): bool {
		$listed = array_flip( array_column( $rows, 'id' ) );
		foreach ( $rows as $row ) {
			if ( 0 !== $row['parent'] && ! isset( $listed[ $row['parent'] ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks a move on the rows, in the order the rules are listed in the update-menu spec, and returns the plan. Reads nothing from the
	 * database. The ability calls it in execute() and the writer calls it again on fresh rows, so both give the same answer for the same rows.
	 *
	 * Returns array{refusal: string|null, target: int[], orders: array<int, int>}. When refusal is one of the MOVE_ codes, target and orders
	 * are empty and nothing is to be written. Otherwise target is the new flat order and orders the new menu_order of each row that changes
	 * (see order_changes()). A move to where the item already is has no orders, and the caller compares the parent for the rest.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows      Rows from items_raw().
	 * @param int                                                                                                 $item_id   The item that moves.
	 * @param int                                                                                                 $parent_id The new parent, or 0.
	 * @param int                                                                                                 $after_id  The new predecessor, or 0.
	 * @return array{refusal: string|null, target: int[], orders: array<int, int>}
	 */
	public static function plan_move( array $rows, int $item_id, int $parent_id, int $after_id ): array {
		$none = array(
			'target' => array(),
			'orders' => array(),
		);

		// Before any other planning work: a menu over the limit is refused as too large, whatever the move.
		if ( count( $rows ) > self::MAX_ITEMS ) {
			return array( 'refusal' => self::MOVE_MENU_TOO_LARGE ) + $none;
		}

		$by_id = self::index( $rows );

		if ( ! isset( $by_id[ $item_id ] )
			|| $item_id === $parent_id
			|| $item_id === $after_id
			|| ( 0 !== $parent_id && ! isset( $by_id[ $parent_id ] ) )
			|| ( 0 !== $after_id && ! isset( $by_id[ $after_id ] ) )
			|| ( 0 !== $after_id && $by_id[ $after_id ]['parent'] !== $parent_id )
			|| self::would_cycle( $rows, $item_id, $parent_id )
		) {
			return array( 'refusal' => self::MOVE_INVALID ) + $none;
		}

		foreach ( array( $item_id, $parent_id, $after_id ) as $id ) {
			if ( 0 !== $id && ( $by_id[ $id ]['orphaned'] || null === self::depth( $rows, $id ) ) ) {
				return array( 'refusal' => self::MOVE_UNSUPPORTED ) + $none;
			}
		}

		// A hidden new parent is refused only when the moved subtree holds a published item: a subtree that is all hidden may go under a
		// hidden parent, which is how its own inverse call puts it back.
		$block = self::subtree_ids( $rows, $item_id );
		if ( 0 !== $parent_id && 'publish' !== $by_id[ $parent_id ]['status'] && self::has_published( $by_id, $block ) ) {
			return array( 'refusal' => self::MOVE_HIDDEN ) + $none;
		}

		$height = self::subtree_height( $rows, $item_id );
		if ( null === $height ) {
			return array( 'refusal' => self::MOVE_UNSUPPORTED ) + $none;
		}

		$parent_depth = 0 === $parent_id ? 0 : (int) self::depth( $rows, $parent_id );
		if ( $parent_depth + $height > self::MAX_DEPTH ) {
			return array( 'refusal' => self::MOVE_DEPTH ) + $none;
		}

		if ( ! self::consistent_with_tree( $rows ) ) {
			return array( 'refusal' => self::MOVE_ORDER ) + $none;
		}

		$target  = self::target_order( $rows, $item_id, $parent_id, $after_id );
		$changes = self::order_changes( $rows, $target );
		if ( null === $changes || ! self::inverse_restores( $rows, $item_id, $parent_id, $target, $changes ) ) {
			return array( 'refusal' => self::MOVE_ORDER ) + $none;
		}

		if ( count( $changes ) > self::MAX_MOVE_ROWS ) {
			return array( 'refusal' => self::MOVE_TOO_LARGE ) + $none;
		}

		return array(
			'refusal' => null,
			'target'  => $target,
			'orders'  => $changes,
		);
	}

	/**
	 * The children of every ID, in row order, built once from the rows. The key 0 holds the top level; a parent that is not in the rows
	 * has its own key too.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows from items_raw().
	 * @return array<int, int[]>
	 */
	public static function child_map( array $rows ): array {
		$children = array();
		foreach ( $rows as $row ) {
			$children[ $row['parent'] ][] = $row['id'];
		}

		return $children;
	}

	/**
	 * The ID that comes before an item among its siblings in the rows, or 0 when it is first among them.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows    Rows from items_raw().
	 * @param int                                                                                                 $item_id The item.
	 */
	public static function predecessor( array $rows, int $item_id ): int {
		$row = self::index( $rows )[ $item_id ] ?? null;
		if ( null === $row ) {
			return 0;
		}

		$previous = 0;
		foreach ( self::children( $rows, $row['parent'] ) as $sibling ) {
			if ( $sibling['id'] === $item_id ) {
				return $previous;
			}

			$previous = $sibling['id'];
		}

		return 0;
	}

	/**
	 * Whether any of the given IDs is a published row.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $by_id Rows keyed by ID.
	 * @param int[]                                                                                               $ids   IDs.
	 */
	private static function has_published( array $by_id, array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) && 'publish' === $by_id[ $id ]['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the inverse of a move gives back the original menu exactly: the item under its old parent after its old predecessor, with
	 * every row's order as it was. The check is made on the rows as the move leaves them. A move that could not be undone this way (for
	 * example one that puts the item on the other side of a dangling row) is refused, rather than accepted without an exact inverse.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows      Rows before the move.
	 * @param int                                                                                                 $item_id   The item.
	 * @param int                                                                                                 $parent_id The new parent.
	 * @param int[]                                                                                               $target    The target flat order.
	 * @param array<int, int>                                                                                     $changes   The new orders.
	 */
	private static function inverse_restores( array $rows, int $item_id, int $parent_id, array $target, array $changes ): bool {
		$by_id      = self::index( $rows );
		$old_parent = $by_id[ $item_id ]['parent'];
		$old_after  = self::predecessor( $rows, $item_id );

		$moved = array();
		foreach ( $target as $id ) {
			$row = $by_id[ $id ];
			if ( $id === $item_id ) {
				$row['parent'] = $parent_id;
			}

			$row['order'] = $changes[ $id ] ?? $row['order'];
			$moved[]      = $row;
		}

		$back_target  = self::target_order( $moved, $item_id, $old_parent, $old_after );
		$back_changes = self::order_changes( $moved, $back_target );
		if ( null === $back_changes ) {
			return false;
		}

		$moved_by_id = self::index( $moved );
		$original    = array_column( $rows, 'id' );
		if ( $back_target !== $original ) {
			return false;
		}

		foreach ( $rows as $row ) {
			$order = $back_changes[ $row['id'] ] ?? $moved_by_id[ $row['id'] ]['order'];
			if ( $order !== $row['order'] ) {
				return false;
			}
		}

		return true;
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
