<?php
/**
 * Tests for the move operation of steadhand/update-menu: the input rules, the refusals, the order that is written, the inverse call, and
 * what a move may and may not change. Most tests are negative.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Log\Entry;
use Steadhand\Pipeline\MenuRules;

/**
 * The fixtures are in MenuWriteTestCase. The writer's own rules are in MenuWriterTest; the pure rules are in MenuRulesTest.
 */
final class UpdateMenuMoveTest extends MenuWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * How many times wp_delete_post() ran (before_delete_post fires inside it).
	 *
	 * @var int
	 */
	private int $deletes = 0;

	/**
	 * How many times a post was saved (save_post fires inside wp_update_post()).
	 *
	 * @var int
	 */
	private int $saves = 0;

	/**
	 * How many times wp_update_nav_menu fired.
	 *
	 * @var int
	 */
	private int $announced = 0;

	/**
	 * Counts the deletes, the saves and the menu announcements.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->on(
			'before_delete_post',
			function (): void {
				++$this->deletes;
			},
			10,
			2
		);
		$this->on(
			'save_post',
			function (): void {
				++$this->saves;
			},
			10,
			0
		);
		$this->on(
			'wp_update_nav_menu',
			function (): void {
				++$this->announced;
			},
			10,
			2
		);
	}

	/**
	 * No test may permanently delete a post.
	 */
	protected function tearDown(): void {
		$this->assertSame( 0, $this->deletes, 'move must never call wp_delete_post().' );
		parent::tearDown();
	}

	/**
	 * Makes the current user an agent with steadhand_menus and steadhand_publish, and returns its ID.
	 *
	 * @param array<string, bool> $overrides Capability overrides, applied after the grants.
	 */
	private function menu_agent( array $overrides = array() ): int {
		return $this->act_as_agent(
			array_merge(
				array(
					Capabilities::MENUS   => true,
					Capabilities::PUBLISH => true,
				),
				$overrides
			)
		);
	}

	/**
	 * The arguments of a call on a classic menu.
	 *
	 * @param int                  $menu The menu.
	 * @param string               $op   The operation.
	 * @param array<string, mixed> $more Further fields.
	 * @return array<string, mixed>
	 */
	private function args( int $menu, string $op, array $more = array() ): array {
		return array_merge(
			array(
				'kind' => 'classic',
				'menu' => $menu,
				'op'   => $op,
			),
			$more
		);
	}

	/**
	 * The arguments of a move.
	 *
	 * @param int $menu       The menu.
	 * @param int $item       The item.
	 * @param int $new_parent The new parent, or 0.
	 * @param int $after      The new predecessor, or 0.
	 * @return array<string, mixed>
	 */
	private function move_args( int $menu, int $item, int $new_parent, int $after ): array {
		return $this->args(
			$menu,
			'move',
			array(
				'item'   => $item,
				'parent' => $new_parent,
				'after'  => $after,
			)
		);
	}

	/**
	 * A classic menu with three published top-level links, A, B and C.
	 *
	 * @return array{menu: int, a: int, b: int, c: int}
	 */
	private function three(): array {
		$menu = $this->classic_menu( 'Move ' . wp_generate_password( 6, false ) );

		return array(
			'menu' => $menu,
			'a'    => $this->custom_item( $menu, 'Alpha', 'https://example.com/alpha', 0, 1 ),
			'b'    => $this->custom_item( $menu, 'Beta', 'https://example.com/beta', 0, 2 ),
			'c'    => $this->custom_item( $menu, 'Gamma', 'https://example.com/gamma', 0, 3 ),
		);
	}

	/**
	 * The menu's rows as ID, parent and order, in flat order, read uncached through the rules.
	 *
	 * @param int $menu The menu.
	 * @return array<int, array{0: int, 1: int, 2: int}>
	 */
	private function shape( int $menu ): array {
		return array_map(
			static fn( array $row ): array => array( $row['id'], $row['parent'], $row['order'] ),
			MenuRules::items_raw( $this->menu_term( $menu ) )
		);
	}

	/**
	 * The item IDs of a menu printed depth first, from the stored parents and the order, as a walker would print them.
	 *
	 * @param int $menu The menu.
	 * @return int[]
	 */
	private function printed( int $menu ): array {
		$rows = MenuRules::items_raw( $this->menu_term( $menu ) );
		$out  = array();
		$walk = null;
		$walk = function ( int $under ) use ( &$walk, &$out, $rows ): void {
			foreach ( MenuRules::children( $rows, $under ) as $row ) {
				$out[] = $row['id'];
				$walk( $row['id'] );
			}
		};
		$walk( 0 );

		return $out;
	}

	/**
	 * The same print, from core's own wp_get_nav_menu_items(), which is what the public menu reads.
	 *
	 * @param int $menu The menu.
	 * @return int[]
	 */
	private function core_printed( int $menu ): array {
		$items = wp_get_nav_menu_items( $menu, array( 'post_status' => 'publish' ) );
		$this->assertIsArray( $items );
		$out  = array();
		$walk = null;
		$walk = function ( int $under ) use ( &$walk, &$out, $items ): void {
			foreach ( $items as $item ) {
				if ( (int) $item->menu_item_parent === $under ) {
					$out[] = (int) $item->ID;
					$walk( (int) $item->ID );
				}
			}
		};
		$walk( 0 );

		return $out;
	}

	/**
	 * A classic menu nested as Save Menu leaves it: A (with child A1), B (with child B1), and C, in that order.
	 *
	 * @return array{menu: int, a: int, a1: int, b: int, b1: int, c: int}
	 */
	private function nested(): array {
		$menu = $this->classic_menu( 'Nested ' . wp_generate_password( 6, false ) );
		$a    = $this->custom_item( $menu, 'A', 'https://example.com/a', 0, 1 );
		$a1   = $this->custom_item( $menu, 'A1', 'https://example.com/a1', $a, 2 );
		$b    = $this->custom_item( $menu, 'B', 'https://example.com/b', 0, 3 );
		$b1   = $this->custom_item( $menu, 'B1', 'https://example.com/b1', $b, 4 );
		$c    = $this->custom_item( $menu, 'C', 'https://example.com/c', 0, 5 );

		return array(
			'menu' => $menu,
			'a'    => $a,
			'a1'   => $a1,
			'b'    => $b,
			'b1'   => $b1,
			'c'    => $c,
		);
	}

	/**
	 * Input: a move takes item, parent and after, and each of the three is required.
	 */
	public function test_a_move_needs_item_parent_and_after(): void {
		$fixture = $this->three();
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'move',
				array(
					'item'   => $fixture['a'],
					'parent' => 0,
				)
			)
		);
		$this->assert_refused( $result, 'steadhand_invalid_input' );

		$result = $this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'move',
				array(
					'item'  => $fixture['a'],
					'after' => 0,
				)
			)
		);
		$this->assert_refused( $result, 'steadhand_invalid_input' );
	}

	/**
	 * Input: an item cannot be its own parent or the item it goes after. Refused before anything is read.
	 */
	public function test_an_item_cannot_be_its_own_parent_or_its_own_after(): void {
		$fixture = $this->three();
		$this->menu_agent();
		$saves = $this->saves;

		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a'], $fixture['a'], 0 ) ),
			'steadhand_invalid_input'
		);
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a'], 0, $fixture['a'] ) ),
			'steadhand_invalid_input'
		);
		$this->assertSame( $saves, $this->saves, 'A refused move saves nothing.' );
	}

	/**
	 * A move under its own child or grandchild would make a cycle, and is refused as invalid input.
	 */
	public function test_a_move_under_its_own_child_or_grandchild_is_refused(): void {
		$fixture = $this->three();
		$child   = $this->custom_item( $fixture['menu'], 'Child', 'https://example.com/child', $fixture['a'], 4 );
		$grand   = $this->custom_item( $fixture['menu'], 'Grand', 'https://example.com/grand', $child, 5 );
		$this->menu_agent();
		$before = $this->shape( $fixture['menu'] );

		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a'], $child, 0 ) ),
			'steadhand_invalid_input'
		);
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a'], $grand, 0 ) ),
			'steadhand_invalid_input'
		);
		$this->assertSame( $before, $this->shape( $fixture['menu'] ), 'Nothing is written.' );
	}

	/**
	 * The after item must be a child of the new parent, with the moved item taken out of consideration.
	 */
	public function test_after_that_is_not_a_child_of_the_parent_is_refused(): void {
		$fixture = $this->three();
		$child   = $this->custom_item( $fixture['menu'], 'Child', 'https://example.com/child', $fixture['a'], 4 );
		$this->menu_agent();

		// B is a top-level item, not a child of A, so it cannot be the predecessor of a move under A.
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], $fixture['a'], $fixture['b'] ) ),
			'steadhand_invalid_input'
		);
		// The child is under A, so it is a valid predecessor under A; C under B would need a child of B.
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], $fixture['b'], $child ) ),
			'steadhand_invalid_input'
		);
	}

	/**
	 * A parent or an after that belongs to another menu is refused as invalid input, and the other menu is not changed.
	 */
	public function test_a_parent_or_after_of_another_menu_is_refused(): void {
		$here  = $this->three();
		$there = $this->three();
		$this->menu_agent();
		$before = $this->shape( $here['menu'] );

		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $here['menu'], $here['c'], $there['a'], 0 ) ),
			'steadhand_invalid_input'
		);
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $here['menu'], $here['c'], 0, $there['b'] ) ),
			'steadhand_invalid_input'
		);
		$this->assertSame( $before, $this->shape( $here['menu'] ) );
	}

	/**
	 * A hidden new parent refuses the move of a visible item and of a hidden one.
	 */
	public function test_a_hidden_new_parent_is_refused_for_a_visible_and_for_a_hidden_item(): void {
		$fixture = $this->three();
		$hidden  = $this->draft_custom_item( $fixture['menu'], 'Hidden parent', 'https://example.com/hp', 0, 4 );
		$gone    = $this->draft_custom_item( $fixture['menu'], 'Hidden child', 'https://example.com/hc', 0, 5 );
		$this->menu_agent();

		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], $hidden, 0 ) ),
			'steadhand_refused_hidden_parent'
		);
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $gone, $hidden, 0 ) ),
			'steadhand_refused_hidden_parent'
		);
	}

	/**
	 * A malformed or orphaned item, parent or after is refused as unsupported, and nothing is written.
	 */
	public function test_a_malformed_or_orphaned_item_parent_or_after_is_refused_as_unsupported(): void {
		$fixture = $this->three();
		$orphan  = $this->draft_custom_item( $fixture['menu'], 'Orphan', 'https://example.com/orphan', 0, 4 );
		update_post_meta( $orphan, MenuRules::ORPHANED_META, '1' );

		$broken = $this->custom_item( $fixture['menu'], 'Broken', 'https://example.com/broken', 0, 5 );
		update_post_meta( $broken, MenuRules::PARENT_META, '99999' );
		$this->menu_agent();
		$before = $this->shape( $fixture['menu'] );

		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], $orphan, 0 ) ),
			'steadhand_refused_unsupported_item'
		);
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $orphan, 0, 0 ) ),
			'steadhand_refused_unsupported_item'
		);
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], $broken, 0 ) ),
			'steadhand_refused_unsupported_item'
		);
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $broken, 0, 0 ) ),
			'steadhand_refused_unsupported_item'
		);
		$this->assertSame( $before, $this->shape( $fixture['menu'] ) );
	}

	/**
	 * A two-level subtree under an item at depth three would reach level five, so it is refused; under depth two it is allowed.
	 */
	public function test_the_depth_limit_counts_the_subtree_height_of_the_moved_item(): void {
		$menu = $this->classic_menu( 'Depth ' . wp_generate_password( 6, false ) );
		$t1   = $this->custom_item( $menu, 'T1', 'https://example.com/t1', 0, 1 );
		$t2   = $this->custom_item( $menu, 'T2', 'https://example.com/t2', $t1, 2 );
		$t3   = $this->custom_item( $menu, 'T3', 'https://example.com/t3', $t2, 3 );
		$m1   = $this->custom_item( $menu, 'M1', 'https://example.com/m1', 0, 4 );
		$this->custom_item( $menu, 'M2', 'https://example.com/m2', $m1, 5 );
		$this->menu_agent();

		// M1 with its child is two levels; under T3, which is at depth three, it would reach level five.
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $menu, $m1, $t3, 0 ) ),
			'steadhand_refused_menu_depth'
		);

		// Under T2, at depth two, the subtree reaches level four, which is allowed.
		$allowed = $this->execute_ability( self::ABILITY, $this->move_args( $menu, $m1, $t2, 0 ) );
		$this->assertTrue( $allowed['changed'] );
	}

	/**
	 * A move that would change more than 50 rows is refused, and nothing is written.
	 */
	public function test_a_move_that_changes_more_than_fifty_rows_is_refused(): void {
		$menu  = $this->classic_menu( 'Big ' . wp_generate_password( 6, false ) );
		$items = array();
		for ( $position = 1; $position <= 51; ++$position ) {
			$items[] = $this->custom_item( $menu, 'Item ' . $position, 'https://example.com/i' . $position, 0, $position );
		}
		$this->menu_agent();
		$before = $this->shape( $menu );

		// The first item goes to the end: all 51 rows change place.
		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $menu, $items[0], 0, $items[50] ) ),
			'steadhand_refused_move_too_large'
		);
		$this->assertSame( $before, $this->shape( $menu ) );
	}

	/**
	 * Duplicate order values in the span would make the result ambiguous, so the move is refused and nothing is written.
	 */
	public function test_duplicate_order_values_in_the_span_are_refused(): void {
		$fixture = $this->three();
		wp_update_post(
			array(
				'ID'         => $fixture['b'],
				'menu_order' => 1,
			)
		);
		$this->menu_agent();
		$before = $this->shape( $fixture['menu'] );

		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], 0, 0 ) ),
			'steadhand_refused_menu_order'
		);
		$this->assertSame( $before, $this->shape( $fixture['menu'] ) );
	}

	/**
	 * Fields that belong to other operations are refused for a move, and a move's after is refused for every other operation.
	 */
	public function test_fields_of_other_operations_are_refused_for_a_move_and_after_is_refused_elsewhere(): void {
		$fixture = $this->three();
		$this->menu_agent();

		$extra = array(
			array( 'title', 'Renamed' ),
			array( 'type', 'custom' ),
			array( 'post_id', 1 ),
			array( 'url', 'https://example.com/new' ),
		);
		foreach ( $extra as [ $field, $value ] ) {
			$more = array(
				'item'   => $fixture['c'],
				'parent' => 0,
				'after'  => 0,
				$field   => $value,
			);
			$this->assert_refused( $this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'move', $more ) ), 'steadhand_invalid_input' );
		}

		foreach ( array( 'hide', 'show', 'update' ) as $op ) {
			$result = $this->execute_ability(
				self::ABILITY,
				$this->args(
					$fixture['menu'],
					$op,
					array(
						'item'  => $fixture['c'],
						'title' => 'X',
						'after' => 0,
					)
				)
			);
			$this->assert_refused( $result, 'steadhand_invalid_input' );
		}
	}

	/**
	 * A move to where the item already is returns changed false, writes nothing, and saves no post.
	 */
	public function test_a_move_to_where_the_item_already_is_writes_nothing(): void {
		$fixture = $this->three();
		$this->menu_agent();
		$before = $this->shape( $fixture['menu'] );
		$saves  = $this->saves;

		$result = $this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['b'], 0, $fixture['a'] ) );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( $before, $this->shape( $fixture['menu'] ) );
		$this->assertSame( $saves, $this->saves, 'No post is saved.' );
		$this->assertSame( 0, $this->announced, 'Nothing is announced.' );
	}

	/**
	 * Each of the three capabilities is needed for a move; without any one of them nothing changes.
	 */
	public function test_a_move_needs_each_of_the_three_capabilities(): void {
		foreach ( array( Capabilities::MENUS, Capabilities::READ, Capabilities::PUBLISH ) as $capability ) {
			$fixture = $this->three();
			$this->menu_agent( array( $capability => false ) );
			$before = $this->shape( $fixture['menu'] );

			$result = $this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], 0, 0 ) );

			$this->assertWPError( $result, "Without $capability the move must be refused." );
			$this->assertSame( $before, $this->shape( $fixture['menu'] ) );
		}
	}

	/**
	 * A block navigation menu cannot be moved in, and nothing is read from it.
	 */
	public function test_a_block_menu_is_refused_for_a_move(): void {
		$fixture = $this->three();
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'   => 'block',
				'menu'   => $fixture['menu'],
				'op'     => 'move',
				'item'   => $fixture['c'],
				'parent' => 0,
				'after'  => 0,
			)
		);

		$this->assert_refused( $result, 'steadhand_invalid_input' );
	}

	/**
	 * A move to the first place under a parent: the item becomes the parent's first child, and the output names the old predecessor.
	 */
	public function test_a_move_to_first_under_a_parent(): void {
		$fixture = $this->nested();
		$this->menu_agent();

		// C goes under A first: its place is directly after A, before A's existing child A1.
		$result = $this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], $fixture['a'], 0 ) );

		$this->assertTrue( $result['changed'] );
		$this->assertSame(
			array( $fixture['a'], $fixture['c'], $fixture['a1'], $fixture['b'], $fixture['b1'] ),
			$this->printed( $fixture['menu'] )
		);
		$this->assertSame( $fixture['b'], $result['previous']['after'], 'Before the move, C came after B at the top level.' );
	}

	/**
	 * A move after a sibling goes directly after it, and the output names that sibling as the predecessor the item had before.
	 */
	public function test_a_move_after_a_sibling_and_the_top_level_first_and_last(): void {
		$fixture = $this->three();
		$this->menu_agent();

		$last = $this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a'], 0, $fixture['c'] ) );
		$this->assertSame( array( $fixture['b'], $fixture['c'], $fixture['a'] ), $this->printed( $fixture['menu'] ), 'A goes last.' );
		$this->assertSame( 0, $last['previous']['after'], 'Before the move, A was first: its predecessor is 0.' );

		$first = $this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a'], 0, 0 ) );
		$this->assertSame( array( $fixture['a'], $fixture['b'], $fixture['c'] ), $this->printed( $fixture['menu'] ), 'A goes first.' );
		$this->assertSame( $fixture['c'], $first['previous']['after'], 'Before the move, A was last: its predecessor is C.' );
	}

	/**
	 * A move carries the item's children, which keep their parent, and the whole block moves. The print is correct, and core's walk agrees.
	 */
	public function test_a_move_carries_the_children_and_core_prints_the_same_tree(): void {
		$fixture = $this->nested();
		$this->menu_agent();

		// A, with its child A1, goes to the top level after C.
		$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a'], 0, $fixture['c'] ) );

		$this->assertSame(
			array( $fixture['b'], $fixture['b1'], $fixture['c'], $fixture['a'], $fixture['a1'] ),
			$this->printed( $fixture['menu'] )
		);
		$this->assertSame( $this->printed( $fixture['menu'] ), $this->core_printed( $fixture['menu'] ), 'Core prints the same tree.' );
		$this->assertSame( (string) $fixture['a'], (string) get_post_meta( $fixture['a1'], MenuRules::PARENT_META, true ), 'The child keeps its parent.' );
	}

	/**
	 * A move across parents: the item leaves one parent and joins another after one of its children.
	 */
	public function test_a_move_across_parents(): void {
		$fixture = $this->nested();
		$this->menu_agent();

		// A1 leaves A and goes under B after B1.
		$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a1'], $fixture['b'], $fixture['b1'] ) );

		$this->assertSame(
			array( $fixture['a'], $fixture['b'], $fixture['b1'], $fixture['a1'], $fixture['c'] ),
			$this->printed( $fixture['menu'] )
		);
		$this->assertSame( $this->printed( $fixture['menu'] ), $this->core_printed( $fixture['menu'] ) );
	}

	/**
	 * A move changes only the menu_order of the rows it reorders and the parent of the moved item. Titles, content, excerpts, statuses and the
	 * other item meta stay as they were.
	 */
	public function test_a_move_changes_only_the_order_and_the_parent(): void {
		$fixture = $this->three();
		$this->menu_agent();
		$before = array();
		foreach ( array( $fixture['a'], $fixture['b'], $fixture['c'] ) as $id ) {
			$post          = $this->raw_row( $id );
			$before[ $id ] = array(
				$post->post_title,
				$post->post_content,
				$post->post_excerpt,
				$post->post_status,
				(string) get_post_meta( $id, '_menu_item_type', true ),
				(string) get_post_meta( $id, '_menu_item_url', true ),
			);
		}

		$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], 0, 0 ) );

		foreach ( array( $fixture['a'], $fixture['b'], $fixture['c'] ) as $id ) {
			$post  = $this->raw_row( $id );
			$after = array(
				$post->post_title,
				$post->post_content,
				$post->post_excerpt,
				$post->post_status,
				(string) get_post_meta( $id, '_menu_item_type', true ),
				(string) get_post_meta( $id, '_menu_item_url', true ),
			);
			$this->assertSame( $before[ $id ], $after, "Item $id keeps its text, status and meta." );
		}
	}

	/**
	 * A hidden item that moves keeps its hide record, so show brings it back.
	 */
	public function test_a_hidden_item_that_moves_can_be_shown_again(): void {
		$fixture = $this->three();
		$this->menu_agent();
		$this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['b'] ) ) );

		$moved = $this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['b'], 0, 0 ) );
		$this->assertTrue( $moved['changed'] );

		$shown = $this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'show', array( 'item' => $fixture['b'] ) ) );
		$this->assertTrue( $shown['changed'], 'The move kept the hide record, so the item can be shown.' );
	}

	/**
	 * A hide record that was already stale before a move is not made valid by it, so show still refuses.
	 */
	public function test_a_stale_hide_record_is_not_rearmed_by_a_move(): void {
		$fixture = $this->three();
		$this->menu_agent();
		$this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['b'] ) ) );
		wp_update_post(
			array(
				'ID'         => $fixture['b'],
				'post_title' => 'Edited outside',
			)
		);
		update_post_meta( $fixture['b'], MenuRules::HIDDEN_MODIFIED_META, '2001-01-01 00:00:00' );

		$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['b'], 0, 0 ) );

		$this->assert_refused(
			$this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'show', array( 'item' => $fixture['b'] ) ) ),
			'steadhand_refused_not_hidden_by_steadhand'
		);
	}

	/**
	 * The output's previous names the parent and the predecessor, so the inverse call restores the exact orders and parents of the whole menu.
	 */
	public function test_previous_and_the_inverse_call_restore_the_exact_state(): void {
		$fixture = $this->three();
		$this->menu_agent();
		$before = $this->shape( $fixture['menu'] );

		// A goes under C. Its old predecessor was nothing: it was first at the top level.
		$moved = $this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['a'], $fixture['c'], 0 ) );
		$this->assertSame( 0, $moved['previous']['parent'] );
		$this->assertSame( 0, $moved['previous']['after'] );

		$back = $this->execute_ability(
			self::ABILITY,
			$this->move_args( $fixture['menu'], $fixture['a'], $moved['previous']['parent'], $moved['previous']['after'] )
		);

		$this->assertTrue( $back['changed'] );
		$this->assertSame( $before, $this->shape( $fixture['menu'] ), 'The inverse restores every order and parent.' );
	}

	/**
	 * The change log names the moved item, and the before state is saved as a snapshot. The call is not undoable.
	 */
	public function test_the_change_log_names_the_moved_item_and_keeps_a_snapshot(): void {
		$fixture = $this->three();
		$agent   = $this->menu_agent();

		$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], 0, $fixture['a'] ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( self::ABILITY, $row['ability'] );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 'nav_menu_item', $row['object_type'] );
		$this->assertSame( $fixture['c'], (int) $row['object_id'] );
		$this->assertGreaterThan( 0, (int) $row['snapshot_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	/**
	 * A move fires the menu-changed action once, however many rows it writes.
	 */
	public function test_a_move_announces_the_menu_once(): void {
		$fixture = $this->three();
		$this->menu_agent();

		$this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], 0, 0 ) );

		$this->assertSame( 1, $this->announced );
	}

	/**
	 * A save that fails midway is a partial failure: the message names the moved item, says the order may be wrong, and tells the agent what
	 * to do. No item is lost.
	 */
	public function test_a_save_that_fails_midway_is_partial_and_names_the_item(): void {
		$fixture = $this->three();
		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ) use ( $fixture ): array {
				if ( (int) ( $postarr['ID'] ?? 0 ) === $fixture['b'] && isset( $postarr['menu_order'] ) ) {
					$data['menu_order'] = 999;
				}

				return $data;
			},
			10,
			2
		);
		$this->menu_agent();

		// C goes first, so A and B are rewritten after C. B's save is changed by the listener.
		$result = $this->execute_ability( self::ABILITY, $this->move_args( $fixture['menu'], $fixture['c'], 0, 0 ) );

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( (string) $fixture['c'], $result->get_error_message() );
		$this->assertStringContainsString( 'get-menu', $result->get_error_message() );
		$this->assertStringContainsString( 'Appearance > Menus', $result->get_error_message() );
		$this->assertCount( 3, MenuRules::items_raw( $this->menu_term( $fixture['menu'] ) ), 'No item is lost.' );
	}
}
