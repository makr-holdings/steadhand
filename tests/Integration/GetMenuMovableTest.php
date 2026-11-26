<?php
/**
 * Tests for get-menu's movable field: true for a menu whose stored order a move can rewrite, false for a menu whose stored order is broken.
 * The agreement test checks movable against MenuRules::plan_move() for every candidate move of each fixture menu.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\MenuRules;

/**
 * The reads run as a dedicated agent. The agreement test calls the planner directly, because it makes no change.
 */
final class GetMenuMovableTest extends MenuWriteTestCase {

	/**
	 * The read ability.
	 */
	private const READ = 'steadhand/get-menu';

	/**
	 * The ability whose moves the agreement test checks.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * Makes the current user a menu agent.
	 */
	private function menu_agent(): int {
		return $this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);
	}

	/**
	 * The movable value get-menu returns for a classic menu.
	 *
	 * @param int $menu Menu term ID.
	 */
	private function movable( int $menu ): bool {
		$output = $this->execute_ability(
			self::READ,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);
		$this->assertIsBool( $output['movable'] );

		return $output['movable'];
	}

	/**
	 * Sets an item's stored menu_order and checks it was stored.
	 *
	 * @param int $item  Item post ID.
	 * @param int $order The stored order.
	 */
	private function set_order( int $item, int $order ): void {
		wp_update_post(
			array(
				'ID'         => $item,
				'menu_order' => $order,
			)
		);
		$this->assertSame( $order, (int) $this->raw_row( $item )->menu_order, 'The stored order must be set for the test.' );
	}

	/**
	 * A nested menu: Parent with Child and Grandchild under Child, then Sibling at the top level. Stored orders are the menu's own, 1 to 4.
	 *
	 * @return array{menu: int, parent: int, child: int, grandchild: int, sibling: int}
	 */
	private function nested_menu(): array {
		$menu       = $this->classic_menu( 'Nested ' . wp_generate_password( 6, false ) );
		$parent     = $this->custom_item( $menu, 'Parent', 'https://example.com/p', 0, 1 );
		$child      = $this->custom_item( $menu, 'Child', 'https://example.com/c', $parent, 2 );
		$grandchild = $this->custom_item( $menu, 'Grandchild', 'https://example.com/g', $child, 3 );
		$sibling    = $this->custom_item( $menu, 'Sibling', 'https://example.com/s', 0, 4 );

		return array(
			'menu'       => $menu,
			'parent'     => $parent,
			'child'      => $child,
			'grandchild' => $grandchild,
			'sibling'    => $sibling,
		);
	}

	/**
	 * A flat menu of three items with stored orders 1, 2 and 3. Returns the menu and the three items.
	 *
	 * @return array{menu: int, a: int, b: int, c: int}
	 */
	private function flat_menu(): array {
		$menu = $this->classic_menu( 'Flat ' . wp_generate_password( 6, false ) );
		$a    = $this->custom_item( $menu, 'Alpha', 'https://example.com/a', 0, 1 );
		$b    = $this->custom_item( $menu, 'Bravo', 'https://example.com/b', 0, 2 );
		$c    = $this->custom_item( $menu, 'Charlie', 'https://example.com/c', 0, 3 );

		return array(
			'menu' => $menu,
			'a'    => $a,
			'b'    => $b,
			'c'    => $c,
		);
	}

	/**
	 * A menu where two items share the stored order 2: Alpha 1, Bravo 2, Charlie 2.
	 */
	private function repeated_menu(): int {
		$fixture = $this->flat_menu();
		$this->set_order( $fixture['c'], 2 );

		return $fixture['menu'];
	}

	/**
	 * A menu where a child is stored before its parent: the child has order 1 and the parent order 2.
	 */
	private function child_before_parent_menu(): int {
		$menu   = $this->classic_menu( 'Child first ' . wp_generate_password( 6, false ) );
		$parent = $this->custom_item( $menu, 'Parent', 'https://example.com/p', 0, 1 );
		$child  = $this->custom_item( $menu, 'Child', 'https://example.com/c', $parent, 2 );
		$this->set_order( $parent, 2 );
		$this->set_order( $child, 1 );

		return $menu;
	}

	/**
	 * A menu with a zero stored order: Alpha 0, Bravo 1, Charlie 2.
	 */
	private function zero_order_menu(): int {
		$fixture = $this->flat_menu();
		$this->set_order( $fixture['a'], 0 );

		return $fixture['menu'];
	}

	/**
	 * A flat menu with gaps in its stored orders: 1, 5 and 9. Every order is positive and increasing, so it is movable.
	 */
	private function gapped_menu(): int {
		$fixture = $this->flat_menu();
		$this->set_order( $fixture['b'], 5 );
		$this->set_order( $fixture['c'], 9 );

		return $fixture['menu'];
	}

	/**
	 * A normal menu is movable.
	 */
	public function test_a_normal_menu_is_movable(): void {
		$fixture = $this->nested_menu();
		$this->menu_agent();

		$this->assertTrue( $this->movable( $fixture['menu'] ) );
	}

	/**
	 * Two items that share a stored order make the menu not movable.
	 */
	public function test_repeated_stored_order_values_make_the_menu_not_movable(): void {
		$menu = $this->repeated_menu();
		$this->menu_agent();

		$this->assertFalse( $this->movable( $menu ) );
	}

	/**
	 * A child ordered before its parent makes the menu not movable.
	 */
	public function test_a_child_ordered_before_its_parent_makes_the_menu_not_movable(): void {
		$menu = $this->child_before_parent_menu();
		$this->menu_agent();

		$this->assertFalse( $this->movable( $menu ) );
	}

	/**
	 * A menu whose middle item has a parent that is not in the menu: Alpha 1, Bravo 2 under a missing parent, Charlie 3. Moving Alpha under
	 * Charlie passes every other check, but its inverse would not restore the menu, so the menu is not movable.
	 */
	private function missing_parent_menu(): int {
		$fixture = $this->flat_menu();
		$this->set_order( $fixture['b'], 2 );
		update_post_meta( $fixture['b'], '_menu_item_menu_item_parent', 987654 );

		return $fixture['menu'];
	}

	/**
	 * A zero stored order makes the menu not movable.
	 */
	public function test_a_zero_order_value_makes_the_menu_not_movable(): void {
		$menu = $this->zero_order_menu();
		$this->menu_agent();

		$this->assertFalse( $this->movable( $menu ) );
	}

	/**
	 * An item whose parent is not in the menu makes the menu not movable, and a move that its inverse cannot restore is refused.
	 */
	public function test_a_parent_that_is_not_in_the_menu_makes_the_menu_not_movable(): void {
		$menu = $this->missing_parent_menu();
		$this->menu_agent();
		$this->assertFalse( $this->movable( $menu ) );

		$fixture = $this->flat_menu_items_of( $menu );
		$result  = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'   => 'classic',
				'menu'   => $menu,
				'op'     => 'move',
				'item'   => $fixture['a'],
				'parent' => $fixture['c'],
				'after'  => 0,
			)
		);

		$this->assert_refused( $result, 'steadhand_refused_menu_order' );
	}

	/**
	 * The three items of a flat menu, found by title, for a test that has only the menu ID.
	 *
	 * @param int $menu Menu term ID.
	 * @return array{a: int, b: int, c: int}
	 */
	private function flat_menu_items_of( int $menu ): array {
		$found = array();
		foreach ( MenuRules::items_raw( $this->menu_term( $menu ) ) as $row ) {
			$found[ $row['post']->post_title ] = $row['id'];
		}

		return array(
			'a' => $found['Alpha'],
			'b' => $found['Bravo'],
			'c' => $found['Charlie'],
		);
	}

	/**
	 * Gaps in the stored order do not matter, so the menu is movable.
	 */
	public function test_gaps_in_the_stored_order_keep_the_menu_movable(): void {
		$menu = $this->gapped_menu();
		$this->menu_agent();

		$this->assertTrue( $this->movable( $menu ) );
	}

	/**
	 * A block navigation post is never movable, because update-menu does nothing to it.
	 */
	public function test_a_block_navigation_menu_is_not_movable(): void {
		$id = $this->block_navigation( 'publish', '<!-- wp:navigation-link {"label":"Home","url":"https://example.com/"} /-->' );
		$this->menu_agent();

		$output = $this->execute_ability(
			self::READ,
			array(
				'kind' => 'block',
				'id'   => $id,
			)
		);

		$this->assertFalse( $output['movable'] );
	}

	/**
	 * The agreement test. For each fixture menu, movable must match what the move planner does: with movable false, at least one candidate
	 * move is refused with steadhand_refused_menu_order; with movable true, none is. Every candidate (an item, a new parent and an after,
	 * each 0 or an item of the menu) is planned by MenuRules::plan_move(), the function update-menu uses, on the same rows.
	 */
	public function test_movable_agrees_with_the_move_planner_on_every_fixture_menu(): void {
		$this->menu_agent();
		$menus = array(
			'nested'         => $this->nested_menu()['menu'],
			'repeated'       => $this->repeated_menu(),
			'child first'    => $this->child_before_parent_menu(),
			'zero order'     => $this->zero_order_menu(),
			'gapped'         => $this->gapped_menu(),
			'missing parent' => $this->missing_parent_menu(),
		);

		foreach ( $menus as $label => $menu ) {
			$term    = $this->menu_term( $menu );
			$rows    = MenuRules::items_raw( $term );
			$ids     = array_column( $rows, 'id' );
			$targets = array_merge( array( 0 ), $ids );
			$refused = array();

			foreach ( $ids as $item ) {
				foreach ( $targets as $parent ) {
					foreach ( $targets as $after ) {
						$refused[] = MenuRules::plan_move( $rows, $item, $parent, $after )['refusal'];
					}
				}
			}

			$order_refused = in_array( MenuRules::MOVE_ORDER, $refused, true );
			$movable       = $this->movable( $menu );

			$this->assertSame( MenuRules::movable( $rows ), $movable, "The $label menu: get-menu and MenuRules must agree." );
			if ( $movable ) {
				$this->assertFalse( $order_refused, "The $label menu is movable, so no move may be refused for its order." );
			} else {
				$this->assertTrue( $order_refused, "The $label menu is not movable, so at least one move must be refused for its order." );
			}
		}
	}

	/**
	 * A move in a movable menu is not refused for its order. Sibling moves to the top level are legal there.
	 */
	public function test_a_legal_move_in_a_movable_menu_is_not_refused_for_its_order(): void {
		$fixture = $this->nested_menu();
		$this->menu_agent();
		$this->assertTrue( $this->movable( $fixture['menu'] ) );

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'   => 'classic',
				'menu'   => $fixture['menu'],
				'op'     => 'move',
				'item'   => $fixture['sibling'],
				'parent' => 0,
				'after'  => $fixture['parent'],
			)
		);

		$this->assertFalse( is_wp_error( $result ), 'A legal move must not be refused.' );
	}

	/**
	 * A move in a menu that is not movable is refused for its order, and nothing is written.
	 */
	public function test_a_move_in_a_menu_that_is_not_movable_is_refused_for_its_order(): void {
		$menu = $this->repeated_menu();
		$this->menu_agent();
		$this->assertFalse( $this->movable( $menu ) );
		// Charlie, last, with stored order 2 that repeats: moving it first rewrites the span that holds the repeat.
		$item = $this->listed_item_ids( $menu )[2];

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'   => 'classic',
				'menu'   => $menu,
				'op'     => 'move',
				'item'   => $item,
				'parent' => 0,
				'after'  => 0,
			)
		);

		$this->assert_refused( $result, 'steadhand_refused_menu_order' );
	}

	/**
	 * The item IDs of a menu in get-menu order.
	 *
	 * @param int $menu Menu term ID.
	 * @return int[]
	 */
	private function listed_item_ids( int $menu ): array {
		$output = $this->execute_ability(
			self::READ,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);

		return array_column( $output['items'], 'id' );
	}
}
