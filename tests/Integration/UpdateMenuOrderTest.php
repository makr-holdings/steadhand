<?php
/**
 * Tests that update-menu reports an item's order as get-menu does: the position get-menu lists the item at, not the stored menu_order.
 * The menus here hold stored order values that repeat, or have gaps, which other tools and imports can leave behind.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Each test calls update-menu as a dedicated agent with steadhand_menus and steadhand_publish, and reads the menu with get-menu.
 */
final class UpdateMenuOrderTest extends MenuWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * The read ability whose order the output must match.
	 */
	private const READ = 'steadhand/get-menu';

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
	 * The order get-menu lists one item at, read through the ability.
	 *
	 * @param int $menu Menu term ID.
	 * @param int $item Item post ID.
	 */
	private function listed_order( int $menu, int $item ): int {
		$output = $this->execute_ability(
			self::READ,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);
		$orders = array_column( $output['items'], 'order', 'id' );
		$this->assertArrayHasKey( $item, $orders, 'get-menu must list the item.' );

		return (int) $orders[ $item ];
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
	 * Three top-level custom links, Alpha, Bravo and Charlie, with stored menu_order values 1, 2 and 2. get-menu lists them at 1, 2 and 3.
	 *
	 * @return array{menu: int, a: int, b: int, c: int}
	 */
	private function repeated_menu(): array {
		$menu = $this->classic_menu( 'Repeated ' . wp_generate_password( 6, false ) );
		$a    = $this->custom_item( $menu, 'Alpha', 'https://example.com/a', 0, 1 );
		$b    = $this->custom_item( $menu, 'Bravo', 'https://example.com/b', 0, 2 );
		$c    = $this->custom_item( $menu, 'Charlie', 'https://example.com/c', 0, 3 );
		$this->set_order( $a, 1 );
		$this->set_order( $b, 2 );
		$this->set_order( $c, 2 );

		return array(
			'menu' => $menu,
			'a'    => $a,
			'b'    => $b,
			'c'    => $c,
		);
	}

	/**
	 * Three top-level custom links with stored orders 1, 5 and 9, which get-menu lists at 1, 2 and 3. Returns the menu and the items.
	 *
	 * @return array{menu: int, a: int, b: int, c: int}
	 */
	private function spread_menu(): array {
		$menu = $this->classic_menu( 'Spread ' . wp_generate_password( 6, false ) );
		$a    = $this->custom_item( $menu, 'Alpha', 'https://example.com/a', 0, 1 );
		$b    = $this->custom_item( $menu, 'Bravo', 'https://example.com/b', 0, 2 );
		$c    = $this->custom_item( $menu, 'Charlie', 'https://example.com/c', 0, 3 );
		$this->set_order( $a, 1 );
		$this->set_order( $b, 5 );
		$this->set_order( $c, 9 );

		return array(
			'menu' => $menu,
			'a'    => $a,
			'b'    => $b,
			'c'    => $c,
		);
	}

	/**
	 * A call of update-menu with one operation on one item.
	 *
	 * @param int    $menu The menu.
	 * @param string $op   The operation.
	 * @param int    $item The item.
	 * @return array<string, mixed>
	 */
	private function call( int $menu, string $op, int $item ): array {
		return $this->execute_ability(
			self::ABILITY,
			array(
				'kind' => 'classic',
				'menu' => $menu,
				'op'   => $op,
				'item' => $item,
			)
		);
	}

	/**
	 * The stored order repeats, so Charlie's stored order is 2 but get-menu lists it at 3. Hide reports 3, the get-menu order, for the item
	 * and for previous.
	 */
	public function test_hide_reports_the_get_menu_order_of_the_third_item(): void {
		$fixture = $this->repeated_menu();
		$this->menu_agent();
		$this->assertSame( 3, $this->listed_order( $fixture['menu'], $fixture['c'] ), 'get-menu lists Charlie third.' );

		$result = $this->call( $fixture['menu'], 'hide', $fixture['c'] );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 3, $result['item']['order'], 'The item order is the get-menu order.' );
		$this->assertSame( 3, $result['previous']['order'], 'The previous order is the get-menu order before the call.' );
		$this->assertSame( 3, $this->listed_order( $fixture['menu'], $fixture['c'] ), 'get-menu agrees after the call.' );
	}

	/**
	 * Show reports the get-menu order of the hidden third item, and previous.order is the same position.
	 */
	public function test_show_reports_the_get_menu_order_of_the_third_item(): void {
		$fixture = $this->repeated_menu();
		$this->menu_agent();
		$this->assertTrue( $this->call( $fixture['menu'], 'hide', $fixture['c'] )['changed'] );

		$result = $this->call( $fixture['menu'], 'show', $fixture['c'] );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 3, $result['item']['order'] );
		$this->assertSame( 3, $result['previous']['order'] );
		$this->assertSame( 3, $this->listed_order( $fixture['menu'], $fixture['c'] ) );
	}

	/**
	 * Update reports the get-menu order of the third item, as the title change does not move it.
	 */
	public function test_update_reports_the_get_menu_order_of_the_third_item(): void {
		$fixture = $this->repeated_menu();
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'  => 'classic',
				'menu'  => $fixture['menu'],
				'op'    => 'update',
				'item'  => $fixture['c'],
				'title' => 'Charlie renamed',
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'Charlie renamed', $result['item']['title'] );
		$this->assertSame( 3, $result['item']['order'] );
		$this->assertSame( 3, $result['previous']['order'] );
	}

	/**
	 * A move reports the get-menu positions of the moved item, before and after. Charlie's stored order is 9 and get-menu lists it at 3;
	 * moving it to the top first gives it position 1. previous.parent and previous.after are unchanged, so the inverse still restores it.
	 */
	public function test_move_reports_the_get_menu_positions_and_keeps_the_inverse(): void {
		$fixture = $this->spread_menu();
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'   => 'classic',
				'menu'   => $fixture['menu'],
				'op'     => 'move',
				'item'   => $fixture['c'],
				'parent' => 0,
				'after'  => 0,
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 1, $result['item']['order'], 'The moved item is first, at get-menu position 1.' );
		$this->assertSame( 3, $result['previous']['order'], 'The previous position was 3, not the stored 9.' );
		$this->assertSame( $fixture['b'], $result['previous']['after'], 'The inverse keeps the predecessor.' );
		$this->assertSame( 0, $result['previous']['parent'] );
		$this->assertSame( 1, $this->listed_order( $fixture['menu'], $fixture['c'] ) );
	}

	/**
	 * An add reports the get-menu position of the new item, which is last. The new stored order is 3, but get-menu lists it at 4.
	 */
	public function test_add_reports_the_get_menu_position_of_the_new_item(): void {
		$fixture = $this->repeated_menu();
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'  => 'classic',
				'menu'  => $fixture['menu'],
				'op'    => 'add',
				'type'  => 'custom',
				'url'   => 'https://example.com/d',
				'title' => 'Delta',
			)
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 4, $result['item']['order'], 'The new item is fourth in get-menu.' );
		$this->assertNull( $result['previous'] );
		$this->assertSame( 4, $this->listed_order( $fixture['menu'], $result['item']['id'] ) );
	}

	/**
	 * An item that get-menu does not list, because its linked page is trashed, has no position, so its order is 0 and the call says why.
	 */
	public function test_an_item_get_menu_does_not_list_has_order_zero_and_a_warning(): void {
		$menu = $this->classic_menu( 'Unlisted ' . wp_generate_password( 6, false ) );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Soon trashed',
			)
		);
		$item = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page,
				'menu-item-title'     => 'Soon trashed',
				'menu-item-status'    => 'publish',
			)
		);
		$this->assertIsInt( $item );
		wp_trash_post( $page );
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind' => 'classic',
				'menu' => $menu,
				'op'   => 'hide',
				'item' => $item,
			)
		);

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : 'The call must succeed.' );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 0, $result['item']['order'], 'get-menu does not list the item, so it has no position.' );
		$this->assertSame( 0, $result['previous']['order'] );
		$this->assertNotEmpty(
			array_filter(
				$result['warnings'],
				static fn( string $text ): bool => str_contains( $text, 'get-menu does not list this item' )
			),
			'The call must say the item has no position.'
		);
	}
}
