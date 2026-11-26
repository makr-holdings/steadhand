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
}
