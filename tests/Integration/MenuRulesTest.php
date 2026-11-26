<?php
/**
 * The read-only menu rules (MenuRules): the items of a classic menu, their menu, parent, depth and subtree, and the marks Steadhand and core
 * leave on them. Malformed data is covered for each rule that walks parents.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\MenuRules;

/**
 * Items are created through core's own menu function, except where a test has to store a value core would not store.
 */
final class MenuRulesTest extends MenuToolTestCase {

	/**
	 * The menu term for an ID.
	 *
	 * @param int $menu Menu term ID.
	 */
	private function term( int $menu ): \WP_Term {
		$term = get_term( $menu, 'nav_menu' );
		$this->assertInstanceOf( \WP_Term::class, $term );

		return $term;
	}

	/**
	 * The rows of a menu, from MenuRules::items_raw().
	 *
	 * @param int $menu Menu term ID.
	 * @return array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}>
	 */
	private function rows( int $menu ): array {
		return MenuRules::items_raw( $this->term( $menu ) );
	}

	/**
	 * The IDs of rows, in order.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, post: \WP_Post}> $rows Rows.
	 * @return int[]
	 */
	private function ids( array $rows ): array {
		return array_column( $rows, 'id' );
	}

	/**
	 * Stores a parent value on an item, as core stores it.
	 *
	 * @param int    $item   Item post ID.
	 * @param string $stored Stored value.
	 */
	private function store_parent( int $item, string $stored ): void {
		update_post_meta( $item, '_menu_item_menu_item_parent', $stored );
	}

	/**
	 * A chain R → c1 → g → h in one menu. Returns the four IDs, from the top down.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int, 4: int}
	 */
	private function chain(): array {
		$menu = $this->classic_menu( 'Rules chain' );
		$r    = $this->custom_item( $menu, 'R', 'https://example.test/r', 0, 1 );
		$c1   = $this->custom_item( $menu, 'c1', 'https://example.test/c1', $r, 2 );
		$g    = $this->custom_item( $menu, 'g', 'https://example.test/g', $c1, 3 );
		$h    = $this->custom_item( $menu, 'h', 'https://example.test/h', $g, 4 );

		return array( $menu, $r, $c1, $g, $h );
	}

	public function test_the_constants_are_the_documented_values(): void {
		$this->assertSame( 500, MenuRules::MAX_ITEMS );
		$this->assertSame( 4, MenuRules::MAX_DEPTH );
		$this->assertSame( 50, MenuRules::MAX_MOVE_ROWS );
		$this->assertSame( '_steadhand_menu_hidden', MenuRules::HIDDEN_META );
		$this->assertSame( '_menu_item_orphaned', MenuRules::ORPHANED_META );
	}

	public function test_items_raw_returns_the_published_and_draft_items_of_this_menu_in_menu_order_only(): void {
		$menu    = $this->classic_menu( 'Rules statuses' );
		$other   = $this->classic_menu( 'Rules other' );
		$live    = $this->custom_item( $menu, 'Live', 'https://example.test/live', 0, 1 );
		$draft   = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'     => 'custom',
				'menu-item-title'    => 'Draft',
				'menu-item-url'      => 'https://example.test/draft',
				'menu-item-status'   => 'draft',
				'menu-item-position' => 2,
			)
		);
		$trashed = $this->custom_item( $menu, 'Trashed', 'https://example.test/trashed', 0, 3 );
		wp_update_post(
			array(
				'ID'          => $trashed,
				'post_status' => 'trash',
			)
		);
		$elsewhere = $this->custom_item( $other, 'Elsewhere', 'https://example.test/else', 0, 1 );

		$rows = $this->rows( $menu );

		$this->assertSame( array( $live, $draft ), $this->ids( $rows ), 'Publish and draft items of this menu only, in menu order.' );
		$this->assertSame( array( 'publish', 'draft' ), array_column( $rows, 'status' ) );
		$this->assertSame( array( 1, 2 ), array_column( $rows, 'order' ) );
		$this->assertSame( array( 0, 0 ), array_column( $rows, 'parent' ) );
		$this->assertInstanceOf( \WP_Post::class, $rows[0]['post'] );
		$this->assertNotContains( $elsewhere, $this->ids( $rows ) );
		$this->assertNotContains( $trashed, $this->ids( $rows ) );
	}

	/**
	 * Control and regression: core's wp_get_nav_menu_items() returns nothing for a menu whose items are all drafts, because the stored
	 * count counts published items only. items_raw() must still return them.
	 */
	public function test_items_raw_is_not_changed_by_a_query_filter(): void {
		$menu = $this->classic_menu( 'Rules query filter' );
		$live = $this->custom_item( $menu, 'Live', 'https://example.test/live', 0, 1 );

		$hide_everything = static fn( string $where ): string => $where . ' AND 1 = 0';
		add_filter( 'posts_where', $hide_everything );
		try {
			$ids = $this->ids( $this->rows( $menu ) );
		} finally {
			remove_filter( 'posts_where', $hide_everything );
		}

		$this->assertSame( array( $live ), $ids, 'A query filter must not hide a stored row from the rules.' );
		$this->assertArrayNotHasKey( 'suppress_filters', MenuRules::query_args( $this->term( $menu ) ), 'The arguments passed on to core\'s menu filter carry no such key, as core\'s own do not.' );
	}

	public function test_items_raw_returns_drafts_of_a_menu_that_has_no_published_item(): void {
		$menu  = $this->classic_menu( 'Rules drafts only' );
		$draft = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'   => 'custom',
				'menu-item-title'  => 'Only draft',
				'menu-item-url'    => 'https://example.test/only',
				'menu-item-status' => 'draft',
			)
		);

		$this->assertSame( array(), wp_get_nav_menu_items( $this->term( $menu ), array( 'post_status' => array( 'publish', 'draft' ) ) ), 'Control: core returns nothing here.' );
		$this->assertSame( array( $draft ), $this->ids( $this->rows( $menu ) ) );
	}

	public function test_items_raw_reads_the_stored_parent_order_and_a_malformed_parent_as_minus_one(): void {
		list( $menu, $r, $c1 ) = $this->chain();
		$odd                   = $this->custom_item( $menu, 'Odd', 'https://example.test/odd', 0, 9 );
		$this->store_parent( $odd, 'abc' );

		$rows = array_column( $this->rows( $menu ), null, 'id' );

		$this->assertSame( 0, $rows[ $r ]['parent'], 'A top-level item.' );
		$this->assertSame( $r, $rows[ $c1 ]['parent'], 'A child carries its parent ID.' );
		$this->assertSame( -1, $rows[ $odd ]['parent'], 'A stored value that is not a whole number is -1.' );
		$this->assertSame( 2, $rows[ $c1 ]['order'], 'The stored menu_order.' );
	}

	public function test_menu_of_returns_the_one_menu_and_null_for_no_menu_two_menus_or_a_non_menu_item(): void {
		$menu   = $this->classic_menu( 'Rules one' );
		$second = $this->classic_menu( 'Rules two' );
		$item   = $this->custom_item( $menu, 'One', 'https://example.test/one', 0, 1 );
		$both   = $this->custom_item( $menu, 'Both', 'https://example.test/both', 0, 2 );
		wp_set_object_terms( $both, array( $menu, $second ), 'nav_menu' );
		$none = wp_insert_post(
			array(
				'post_type'   => 'nav_menu_item',
				'post_status' => 'publish',
				'post_title'  => 'None',
			)
		);
		$post = $this->admin_post();

		$found = MenuRules::menu_of( get_post( $item ) );
		$this->assertInstanceOf( \WP_Term::class, $found );
		$this->assertSame( $menu, $found->term_id );
		$this->assertNull( MenuRules::menu_of( get_post( $both ) ), 'An item in two menus.' );
		$this->assertNull( MenuRules::menu_of( get_post( $none ) ), 'An item in no menu.' );
		$this->assertNull( MenuRules::menu_of( get_post( $post ) ), 'A post that is not a menu item.' );
	}

	public function test_children_returns_the_direct_children_in_row_order(): void {
		list( $menu, $r, $c1, $g ) = $this->chain();
		$c2                        = $this->custom_item( $menu, 'c2', 'https://example.test/c2', $r, 5 );
		$rows                      = $this->rows( $menu );

		$this->assertSame( array( $c1, $c2 ), $this->ids( MenuRules::children( $rows, $r ) ) );
		$this->assertSame( array( $g ), $this->ids( MenuRules::children( $rows, $c1 ) ) );
		$this->assertSame( array(), MenuRules::children( $rows, $c2 ) );
		$this->assertSame( array( $r ), $this->ids( MenuRules::children( $rows, 0 ) ), 'Children of 0 are the top level.' );
	}

	public function test_depth_counts_the_levels_from_the_top_with_the_top_level_as_one(): void {
		list( $menu, $r, $c1, $g, $h ) = $this->chain();
		$rows                          = $this->rows( $menu );

		$this->assertSame( 1, MenuRules::depth( $rows, $r ) );
		$this->assertSame( 2, MenuRules::depth( $rows, $c1 ) );
		$this->assertSame( 4, MenuRules::depth( $rows, $h ) );
	}

	/**
	 * Malformed data: a parent that is missing, belongs to another menu, is malformed, points at itself or forms a loop gives null.
	 */
	public function test_depth_is_null_for_a_missing_parent_another_menus_parent_a_self_parent_a_loop_and_a_malformed_parent(): void {
		$menu    = $this->classic_menu( 'Rules broken' );
		$other   = $this->classic_menu( 'Rules broken other' );
		$x       = $this->custom_item( $menu, 'X', 'https://example.test/x', 0, 1 );
		$y       = $this->custom_item( $menu, 'Y', 'https://example.test/y', 0, 2 );
		$z       = $this->custom_item( $menu, 'Z', 'https://example.test/z', 0, 3 );
		$w       = $this->custom_item( $menu, 'W', 'https://example.test/w', 0, 4 );
		$m       = $this->custom_item( $menu, 'M', 'https://example.test/m', 0, 5 );
		$alien   = $this->custom_item( $other, 'Alien', 'https://example.test/alien', 0, 1 );
		$missing = $this->custom_item( $menu, 'Missing', 'https://example.test/missing', 0, 6 );

		$this->store_parent( $missing, '999999999' );
		$this->store_parent( $y, (string) $alien );
		$this->store_parent( $z, (string) $z );
		$this->store_parent( $w, 'abc' );
		$this->store_parent( $m, (string) $x );
		$this->store_parent( $x, (string) $m );

		$rows = $this->rows( $menu );

		$this->assertNull( MenuRules::depth( $rows, $x ), 'A loop: X and M point at each other.' );
		$this->assertNull( MenuRules::depth( $rows, $m ), 'A loop reached from M.' );
		$this->assertNull( MenuRules::depth( $rows, $missing ), 'A parent ID that is not an item.' );
		$this->assertNull( MenuRules::depth( $rows, $y ), 'A parent of another menu is not in the rows.' );
		$this->assertNull( MenuRules::depth( $rows, $z ), 'A self parent.' );
		$this->assertNull( MenuRules::depth( $rows, $w ), 'A malformed parent.' );
		$this->assertNull( MenuRules::depth( $rows, 999999999 ), 'An ID that is not in the menu.' );
	}

	public function test_subtree_height_counts_the_subtree_with_its_root_as_one_level(): void {
		list( $menu, $r, $c1 ) = $this->chain();
		$c2                    = $this->custom_item( $menu, 'c2', 'https://example.test/c2', $r, 5 );
		$rows                  = $this->rows( $menu );

		$this->assertSame( 4, MenuRules::subtree_height( $rows, $r ), 'R, c1, g, h.' );
		$this->assertSame( 3, MenuRules::subtree_height( $rows, $c1 ), 'c1, g, h.' );
		$this->assertSame( 1, MenuRules::subtree_height( $rows, $c2 ), 'A leaf.' );
	}

	public function test_subtree_height_is_null_for_a_loop_and_for_an_item_that_is_not_in_the_menu(): void {
		$menu = $this->classic_menu( 'Rules loop subtree' );
		$a    = $this->custom_item( $menu, 'A', 'https://example.test/a', 0, 1 );
		$b    = $this->custom_item( $menu, 'B', 'https://example.test/b', $a, 2 );
		$this->store_parent( $a, (string) $b );
		$rows = $this->rows( $menu );

		$this->assertNull( MenuRules::subtree_height( $rows, $a ) );
		$this->assertNull( MenuRules::subtree_height( $rows, 999999999 ) );
	}

	public function test_would_cycle_refuses_the_item_itself_and_its_descendants_and_allows_the_rest(): void {
		list( $menu, $r, $c1, $g ) = $this->chain();
		$o                         = $this->custom_item( $menu, 'O', 'https://example.test/o', 0, 9 );
		$rows                      = $this->rows( $menu );

		$this->assertTrue( MenuRules::would_cycle( $rows, $r, $r ), 'An item as its own parent.' );
		$this->assertTrue( MenuRules::would_cycle( $rows, $r, $g ), 'A descendant as the new parent.' );
		$this->assertFalse( MenuRules::would_cycle( $rows, $g, $r ), 'An ancestor as the new parent is allowed.' );
		$this->assertFalse( MenuRules::would_cycle( $rows, $o, 0 ), 'The top level.' );
		$this->assertFalse( MenuRules::would_cycle( $rows, $o, $c1 ), 'An unrelated item.' );
	}

	/**
	 * A loop that does not include the item cannot be walked to its end. The walk is capped, and the answer is true.
	 */
	public function test_would_cycle_is_true_when_the_walk_up_from_the_new_parent_loops_elsewhere(): void {
		$menu = $this->classic_menu( 'Rules loop elsewhere' );
		$x    = $this->custom_item( $menu, 'X', 'https://example.test/x', 0, 1 );
		$y    = $this->custom_item( $menu, 'Y', 'https://example.test/y', $x, 2 );
		$z    = $this->custom_item( $menu, 'Z', 'https://example.test/z', 0, 3 );
		$this->store_parent( $x, (string) $y );
		$rows = $this->rows( $menu );

		$this->assertTrue( MenuRules::would_cycle( $rows, $z, $x ) );
	}

	public function test_hidden_by_steadhand_is_true_only_for_one_row_holding_a_user_id(): void {
		$menu = $this->classic_menu( 'Rules marker' );
		$item = $this->custom_item( $menu, 'Marked', 'https://example.test/marked', 0, 1 );
		$two  = $this->custom_item( $menu, 'Two', 'https://example.test/two', 0, 2 );
		$bad  = $this->custom_item( $menu, 'Bad', 'https://example.test/bad', 0, 3 );
		$zero = $this->custom_item( $menu, 'Zero', 'https://example.test/zero', 0, 4 );

		$this->assertFalse( MenuRules::hidden_by_steadhand( $item ), 'No marker.' );

		update_post_meta( $item, MenuRules::HIDDEN_META, (string) $this->admin_id() );
		$this->assertTrue( MenuRules::hidden_by_steadhand( $item ), 'One marker holding a user ID.' );

		add_post_meta( $two, MenuRules::HIDDEN_META, '5' );
		add_post_meta( $two, MenuRules::HIDDEN_META, '5' );
		$this->assertFalse( MenuRules::hidden_by_steadhand( $two ), 'Two rows.' );

		update_post_meta( $bad, MenuRules::HIDDEN_META, 'yes' );
		$this->assertFalse( MenuRules::hidden_by_steadhand( $bad ), 'A value that is not a user ID.' );

		update_post_meta( $zero, MenuRules::HIDDEN_META, '0' );
		$this->assertFalse( MenuRules::hidden_by_steadhand( $zero ), 'Zero is not a user ID.' );
	}

	public function test_is_orphaned_is_true_when_the_orphan_mark_is_present(): void {
		$menu = $this->classic_menu( 'Rules orphan' );
		$item = $this->custom_item( $menu, 'Orphan', 'https://example.test/orphan', 0, 1 );

		$this->assertFalse( MenuRules::is_orphaned( $item ) );

		update_post_meta( $item, MenuRules::ORPHANED_META, '1700000000' );
		$this->assertTrue( MenuRules::is_orphaned( $item ) );
		$this->assertFalse( MenuRules::is_orphaned( 0 ) );
	}

	public function test_parse_parent_reads_digits_as_an_id_and_everything_else_as_minus_one_or_zero(): void {
		$this->assertSame( 0, MenuRules::parse_parent( '' ) );
		$this->assertSame( 0, MenuRules::parse_parent( null ) );
		$this->assertSame( 0, MenuRules::parse_parent( '0' ) );
		$this->assertSame( 7, MenuRules::parse_parent( '7' ) );
		$this->assertSame( 7, MenuRules::parse_parent( 7 ) );
		$this->assertSame( -1, MenuRules::parse_parent( '-5' ) );
		$this->assertSame( -1, MenuRules::parse_parent( '12abc' ) );
		$this->assertSame( -1, MenuRules::parse_parent( array( 1 ) ) );
	}

	/**
	 * A row as items_raw() returns it, built from its fields. The pure rules read only the ID, parent, order, status and orphan mark.
	 *
	 * @param int    $id       Item ID.
	 * @param int    $new_parent Parent ID, or 0.
	 * @param int    $order    menu_order.
	 * @param string $status   publish or draft.
	 * @param bool   $orphaned Whether the orphan mark is set.
	 * @return array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}
	 */
	private function row( int $id, int $new_parent, int $order, string $status = 'publish', bool $orphaned = false ): array {
		return array(
			'id'       => $id,
			'parent'   => $new_parent,
			'order'    => $order,
			'status'   => $status,
			'orphaned' => $orphaned,
			'post'     => new \WP_Post(
				(object) array(
					'ID'          => $id,
					'post_type'   => 'nav_menu_item',
					'post_status' => $status,
					'menu_order'  => $order,
				)
			),
		);
	}

	/**
	 * The sample menu the pure move tests use, in menu order: A with children A1 and A2; B with child B1, which has child B1a; C with child C1; and a
	 * hidden top-level item D, last.
	 *
	 * @return array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}>
	 */
	private function sample_tree(): array {
		return array(
			$this->row( 1, 0, 1 ),
			$this->row( 2, 1, 2 ),
			$this->row( 3, 1, 3 ),
			$this->row( 4, 0, 4 ),
			$this->row( 5, 4, 5 ),
			$this->row( 6, 5, 6 ),
			$this->row( 7, 0, 7 ),
			$this->row( 8, 7, 8 ),
			$this->row( 9, 0, 9, 'draft' ),
		);
	}

	/**
	 * The shape of a menu that a move must restore: each item's ID, parent and order, in flat order.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows.
	 * @return array<int, array{0: int, 1: int, 2: int}>
	 */
	private function shape( array $rows ): array {
		return array_map( static fn( array $row ): array => array( $row['id'], $row['parent'], $row['order'] ), $rows );
	}

	/**
	 * The rows after a plan is applied: the target order, the parent of the moved item, and the new order of each changed row.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows    Rows.
	 * @param int                                                                                                 $item    The moved item.
	 * @param int                                                                                                 $new_parent  The new parent.
	 * @param array{refusal: string|null, target: int[], orders: array<int, int>}                                 $plan    A plan without refusal.
	 * @return array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}>
	 */
	private function applied( array $rows, int $item, int $new_parent, array $plan ): array {
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ $row['id'] ] = $row;
		}

		$out = array();
		foreach ( $plan['target'] as $id ) {
			$row = $by_id[ $id ];
			if ( $item === $id ) {
				$row['parent'] = $new_parent;
			}

			$row['order'] = $plan['orders'][ $id ] ?? $row['order'];
			$out[]        = $row;
		}

		return $out;
	}

	/**
	 * The predecessor of an item among its siblings in the rows, or 0.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $rows Rows.
	 * @param int                                                                                                 $item Item.
	 */
	private function predecessor( array $rows, int $item ): int {
		$parent = 0;
		foreach ( $rows as $row ) {
			if ( $row['id'] === $item ) {
				$parent = $row['parent'];
			}
		}

		$previous = 0;
		foreach ( MenuRules::children( $rows, $parent ) as $sibling ) {
			if ( $sibling['id'] === $item ) {
				return $previous;
			}

			$previous = $sibling['id'];
		}

		return 0;
	}

	public function test_subtree_ids_follows_the_parent_links_and_not_the_order(): void {
		// Item 9 is a child of 1 but sits far from it in the order: the subtree must still hold it.
		$rows = array(
			$this->row( 1, 0, 1 ),
			$this->row( 2, 1, 2 ),
			$this->row( 3, 0, 3 ),
			$this->row( 4, 0, 4 ),
			$this->row( 9, 1, 5 ),
		);

		$this->assertSame( array( 1, 2, 9 ), MenuRules::subtree_ids( $rows, 1 ) );
		$this->assertSame( array( 3 ), MenuRules::subtree_ids( $rows, 3 ) );
		$this->assertSame( array(), MenuRules::subtree_ids( $rows, 77 ), 'An ID that is not in the rows has no subtree.' );
	}

	public function test_subtree_ids_ends_at_a_loop_and_lists_each_item_once(): void {
		$rows = array(
			$this->row( 1, 2, 1 ),
			$this->row( 2, 1, 2 ),
		);

		$this->assertSame( array( 1, 2 ), MenuRules::subtree_ids( $rows, 1 ) );
	}

	public function test_target_order_puts_the_block_after_the_subtree_of_the_predecessor(): void {
		// A (with A1 and A2) goes to the top level after B, whose subtree is B, B1, B1a.
		$rows = $this->sample_tree();

		$this->assertSame( array( 4, 5, 6, 1, 2, 3, 7, 8, 9 ), MenuRules::target_order( $rows, 1, 0, 4 ) );
	}

	public function test_target_order_first_under_a_parent_goes_directly_after_the_parent(): void {
		// C1 moves under B first: B, then C1, then B's own children.
		$rows = $this->sample_tree();

		$this->assertSame( array( 1, 2, 3, 4, 8, 5, 6, 7, 9 ), MenuRules::target_order( $rows, 8, 4, 0 ) );
	}


	public function test_target_order_top_level_first_goes_to_the_start_with_the_children(): void {
		// C (with its child C1) moves to the top level first: C and C1 come before everything else.
		$rows = $this->sample_tree();

		$this->assertSame( array( 7, 8, 1, 2, 3, 4, 5, 6, 9 ), MenuRules::target_order( $rows, 7, 0, 0 ) );
	}


	public function test_target_order_top_level_last_goes_after_the_last_top_level_subtree(): void {
		// A (with A1 and A2) moves to the top level after C, whose subtree is C and C1; the hidden D is a sibling too.
		$rows = $this->sample_tree();

		$this->assertSame( array( 4, 5, 6, 7, 8, 1, 2, 3, 9 ), MenuRules::target_order( $rows, 1, 0, 7 ) );
	}


	public function test_target_order_moves_the_children_with_the_item_across_parents(): void {
		// Item 1 (with child 2) goes under 3 after 4, which is 3's only child: 1 and 2 come after 4 and carry their own order.
		$rows = array(
			$this->row( 1, 0, 1 ),
			$this->row( 2, 1, 2 ),
			$this->row( 3, 0, 3 ),
			$this->row( 4, 3, 4 ),
			$this->row( 5, 0, 5 ),
		);

		$this->assertSame( array( 3, 4, 1, 2, 5 ), MenuRules::target_order( $rows, 1, 3, 4 ) );
	}


	public function test_order_changes_rewrites_only_the_span_with_the_values_it_had(): void {
		$rows   = $this->sample_tree();
		$target = MenuRules::target_order( $rows, 1, 0, 4 );

		// Positions 0 to 5 change and take the orders 1 to 6 in turn: B's rows get 1 to 3, A's rows get 4 to 6. Item 7 is untouched.
		$this->assertSame(
			array(
				4 => 1,
				5 => 2,
				6 => 3,
				1 => 4,
				2 => 5,
				3 => 6,
			),
			MenuRules::order_changes( $rows, $target )
		);
	}

	public function test_order_changes_is_empty_when_nothing_moves(): void {
		$rows = $this->sample_tree();

		$this->assertSame( array(), MenuRules::order_changes( $rows, array_column( $rows, 'id' ) ) );
	}


	public function test_order_changes_refuses_a_span_with_repeated_or_non_positive_values_and_a_tie_at_its_edge(): void {
		$repeated = array(
			$this->row( 1, 0, 1 ),
			$this->row( 2, 0, 2 ),
			$this->row( 3, 0, 2 ),
			$this->row( 4, 0, 4 ),
		);
		$this->assertNull( MenuRules::order_changes( $repeated, array( 1, 3, 2, 4 ) ), 'Rows 2 and 3 share the value 2 inside the span.' );

		$zero = array(
			$this->row( 1, 0, 0 ),
			$this->row( 2, 0, 1 ),
		);
		$this->assertNull( MenuRules::order_changes( $zero, array( 2, 1 ) ), 'An order of 0 is not a positive integer.' );

		// The span is positions 0 and 1; the row after it has the value 2 as well, so the sort could put it in the wrong place.
		$tie = array(
			$this->row( 1, 0, 1 ),
			$this->row( 2, 0, 2 ),
			$this->row( 3, 0, 2 ),
		);
		$this->assertNull( MenuRules::order_changes( $tie, array( 2, 1, 3 ) ), 'A row just outside the span has the value of the span edge.' );
	}

	public function test_consistent_with_tree_is_false_for_a_child_before_its_parent_and_for_an_interleaved_subtree(): void {
		$this->assertTrue( MenuRules::consistent_with_tree( $this->sample_tree() ) );

		$child_first = array(
			$this->row( 2, 1, 1 ),
			$this->row( 1, 0, 2 ),
		);
		$this->assertFalse( MenuRules::consistent_with_tree( $child_first ) );

		$interleaved = array(
			$this->row( 1, 0, 1 ),
			$this->row( 2, 1, 2 ),
			$this->row( 3, 0, 3 ),
			$this->row( 4, 2, 4 ),
		);
		$this->assertFalse( MenuRules::consistent_with_tree( $interleaved ), 'Item 3 sits inside the subtree of 1.' );
	}

	public function test_plan_move_refuses_an_after_that_is_not_a_child_of_the_parent_and_a_missing_item(): void {
		$rows = $this->sample_tree();

		$this->assertSame( MenuRules::MOVE_INVALID, MenuRules::plan_move( $rows, 8, 4, 1 )['refusal'], 'After 1 is not a child of 4.' );
		$this->assertSame( MenuRules::MOVE_INVALID, MenuRules::plan_move( $rows, 8, 77, 0 )['refusal'], 'A parent that is not in the menu.' );
		$this->assertSame( MenuRules::MOVE_INVALID, MenuRules::plan_move( $rows, 8, 7, 77 )['refusal'], 'An after that is not in the menu.' );
		$this->assertSame( MenuRules::MOVE_INVALID, MenuRules::plan_move( $rows, 77, 0, 0 )['refusal'], 'An item that is not in the menu.' );
	}

	public function test_plan_move_refuses_a_move_under_itself_or_under_its_own_descendants(): void {
		$rows = $this->sample_tree();

		$this->assertSame( MenuRules::MOVE_INVALID, MenuRules::plan_move( $rows, 4, 4, 0 )['refusal'], 'Under itself.' );
		$this->assertSame( MenuRules::MOVE_INVALID, MenuRules::plan_move( $rows, 4, 5, 0 )['refusal'], 'Under its child.' );
		$this->assertSame( MenuRules::MOVE_INVALID, MenuRules::plan_move( $rows, 4, 6, 0 )['refusal'], 'Under its grandchild.' );
		$this->assertSame( MenuRules::MOVE_INVALID, MenuRules::plan_move( $rows, 4, 0, 4 )['refusal'], 'After itself.' );
	}



	public function test_plan_move_refuses_a_hidden_new_parent_only_when_the_moved_subtree_has_a_published_item(): void {
		$rows = array_merge( $this->sample_tree(), array( $this->row( 10, 0, 10, 'draft' ) ) );

		$this->assertSame( MenuRules::MOVE_HIDDEN, MenuRules::plan_move( $rows, 2, 9, 0 )['refusal'], 'A visible item under the hidden item 9.' );
		$this->assertNull( MenuRules::plan_move( $rows, 9, 10, 0 )['refusal'], 'A hidden item with nothing published under it may go under a hidden parent.' );
		$this->assertNull( MenuRules::plan_move( $rows, 9, 7, 0 )['refusal'], 'A hidden item may go under a visible parent.' );

		$with_child = array_merge( $rows, array( $this->row( 11, 9, 11 ) ) );
		$this->assertSame( MenuRules::MOVE_HIDDEN, MenuRules::plan_move( $with_child, 9, 10, 0 )['refusal'], 'A subtree with a published item may not go under a hidden parent.' );
	}

	public function test_plan_move_refuses_an_orphaned_item_or_parent_and_a_malformed_item(): void {
		$rows = array(
			$this->row( 1, 0, 1, 'publish', true ),
			$this->row( 2, 0, 2 ),
			$this->row( 3, 99, 3 ),
		);

		$this->assertSame( MenuRules::MOVE_UNSUPPORTED, MenuRules::plan_move( $rows, 1, 2, 0 )['refusal'], 'The item is orphaned.' );
		$this->assertSame( MenuRules::MOVE_UNSUPPORTED, MenuRules::plan_move( $rows, 2, 1, 0 )['refusal'], 'The new parent is orphaned.' );
		$this->assertSame( MenuRules::MOVE_UNSUPPORTED, MenuRules::plan_move( $rows, 3, 2, 0 )['refusal'], 'The item has a parent that is not in the menu.' );
		$this->assertSame( MenuRules::MOVE_UNSUPPORTED, MenuRules::plan_move( $rows, 2, 3, 0 )['refusal'], 'The new parent is malformed.' );
	}


	public function test_plan_move_refuses_a_result_deeper_than_the_menu_allows_by_the_subtree_height(): void {
		// Chain 1, 2, 3: item 3 is at depth 3. Item 4 has one child, so its subtree is 2 levels: under 3 it would reach level 5.
		$rows = array(
			$this->row( 1, 0, 1 ),
			$this->row( 2, 1, 2 ),
			$this->row( 3, 2, 3 ),
			$this->row( 4, 0, 4 ),
			$this->row( 5, 4, 5 ),
		);

		$this->assertSame( 2, MenuRules::subtree_height( $rows, 4 ) );
		$this->assertSame( MenuRules::MOVE_DEPTH, MenuRules::plan_move( $rows, 4, 3, 0 )['refusal'] );
		$this->assertNull( MenuRules::plan_move( $rows, 4, 2, 0 )['refusal'], 'Under item 2, the deepest item reaches level 4, which is allowed.' );
	}


	public function test_plan_move_refuses_more_than_the_move_row_limit(): void {
		$rows = array();
		for ( $id = 1; $id <= 60; ++$id ) {
			$rows[] = $this->row( $id, 0, $id );
		}

		// Item 1 goes to the end: every one of the 60 rows changes place.
		$this->assertSame( MenuRules::MOVE_TOO_LARGE, MenuRules::plan_move( $rows, 1, 0, 60 )['refusal'] );

		// Item 1 goes to the end of a menu of 50: 50 rows change, which is allowed.
		$fifty = array_slice( $rows, 0, 50 );
		$this->assertNull( MenuRules::plan_move( $fifty, 1, 0, 50 )['refusal'], 'Exactly MAX_MOVE_ROWS rows may change.' );
	}

	public function test_plan_move_refuses_an_inconsistent_order_and_plans_a_move_to_the_same_place_as_no_change(): void {
		$duplicated = array(
			$this->row( 1, 0, 1 ),
			$this->row( 2, 0, 2 ),
			$this->row( 3, 0, 2 ),
		);
		$this->assertSame( MenuRules::MOVE_ORDER, MenuRules::plan_move( $duplicated, 1, 0, 3 )['refusal'] );

		$rows = $this->sample_tree();
		$same = MenuRules::plan_move( $rows, 2, 1, 0 );
		$this->assertNull( $same['refusal'] );
		$this->assertSame( array(), $same['orders'], 'A move to where the item is changes no order.' );
		$this->assertSame( array_column( $rows, 'id' ), $same['target'] );
	}

	public function test_every_valid_move_is_undone_by_its_inverse_and_lands_after_the_predecessor(): void {
		$rows  = $this->sample_tree();
		$moves = 0;

		foreach ( $rows as $item ) {
			$candidates = array_merge( array( 0 ), array_column( $rows, 'id' ) );
			foreach ( $candidates as $parent ) {
				foreach ( $candidates as $after ) {
					$plan = MenuRules::plan_move( $rows, $item['id'], $parent, $after );
					if ( null !== $plan['refusal'] ) {
						continue;
					}

					$moved = $this->applied( $rows, $item['id'], $parent, $plan );
					$this->assertSame( $after, $this->predecessor( $moved, $item['id'] ), 'The item must sit directly after its predecessor.' );

					$back = MenuRules::plan_move( $moved, $item['id'], $item['parent'], $this->predecessor( $rows, $item['id'] ) );
					$this->assertNull( $back['refusal'], 'The inverse of a valid move is valid.' );
					$this->assertSame( $this->shape( $rows ), $this->shape( $this->applied( $moved, $item['id'], $item['parent'], $back ) ) );
					++$moves;
				}
			}
		}

		$this->assertGreaterThan( 30, $moves, 'The sample menu must give many valid moves.' );
	}

	public function test_first_at_the_top_level_goes_before_the_first_top_level_item_not_before_a_dangling_row(): void {
		// X is a dangling row (its parent 99 is not in the menu) and comes first. A is already the first top-level item.
		$rows = array( $this->row( 1, 99, 1 ), $this->row( 2, 0, 2 ), $this->row( 3, 0, 3 ) );

		$this->assertSame( array( 1, 2, 3 ), MenuRules::target_order( $rows, 2, 0, 0 ) );
	}

	public function test_the_reviewers_dangling_case_moves_and_its_inverse_restores_every_order_and_parent(): void {
		// X dangles first; A and B are top-level. Move A after B, then move A back to the top level after its old predecessor.
		$rows = array( $this->row( 1, -1, 1 ), $this->row( 2, 0, 2 ), $this->row( 3, 0, 3 ) );

		$plan = MenuRules::plan_move( $rows, 2, 0, 3 );
		$this->assertNull( $plan['refusal'] );
		$moved = $this->applied( $rows, 2, 0, $plan );

		$back = MenuRules::plan_move( $moved, 2, 0, MenuRules::predecessor( $rows, 2 ) );
		$this->assertNull( $back['refusal'] );
		$this->assertSame( $this->shape( $rows ), $this->shape( $this->applied( $moved, 2, 0, $back ) ) );
	}

	public function test_a_move_across_a_dangling_row_that_cannot_be_undone_exactly_is_refused_as_menu_order(): void {
		// A is first, X dangles between A and B. Moving A after B puts it on the far side of X, and the inverse (first at the top level,
		// before the first top-level item) cannot put it back in front of X.
		$rows = array( $this->row( 1, 0, 1 ), $this->row( 2, 99, 2 ), $this->row( 3, 0, 3 ) );

		$this->assertSame( MenuRules::MOVE_ORDER, MenuRules::plan_move( $rows, 1, 0, 3 )['refusal'] );
	}

	public function test_a_menu_over_the_item_limit_is_refused_before_any_planning(): void {
		$rows = array();
		for ( $id = 1; $id <= 501; ++$id ) {
			$rows[] = $this->row( $id, 0, $id );
		}

		// Item 9999 is not in the menu: were the planning reached, the refusal would be steadhand_invalid_input.
		$this->assertSame( MenuRules::MOVE_MENU_TOO_LARGE, MenuRules::plan_move( $rows, 9999, 0, 0 )['refusal'] );
	}

	public function test_planning_a_500_item_chain_is_quick_and_the_tree_check_holds(): void {
		$rows = array();
		for ( $id = 1; $id <= 500; ++$id ) {
			$rows[] = $this->row( $id, 1 === $id ? 0 : $id - 1, $id );
		}

		$start      = microtime( true );
		$consistent = MenuRules::consistent_with_tree( $rows );
		$plan       = MenuRules::plan_move( $rows, 500, 1, 0 );
		$elapsed    = microtime( true ) - $start;

		$this->assertTrue( $consistent );
		$this->assertSame( MenuRules::MOVE_TOO_LARGE, $plan['refusal'], 'The leaf moves under item 1, so 499 rows change: refused by the row limit, after planning.' );
		$this->assertLessThan( 1.0, $elapsed, 'The pure planning of a 500-item chain must take well under a second.' );
	}
}
