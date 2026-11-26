<?php
/**
 * Tests for the read bracket update-menu uses to build get-menu's list of a menu (Pipeline::as_read()). The list runs third-party
 * filters, so those filters must not see the write grant. A throw from them must not fail a completed write, and a dropped item
 * gets the position 0 with the warning.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\MenuWriter;
use Steadhand\Pipeline\Pipeline;

/**
 * Each test calls update-menu (a hide of one visible item) as a dedicated agent with steadhand_menus and steadhand_publish.
 */
final class UpdateMenuReadBracketTest extends MenuWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * The warning get-menu's absence gives, as the code says it.
	 */
	private const UNLISTED = 'get-menu does not list this item (for example its linked object is trashed or missing, or other code on the site leaves it out), so its position is given as 0.';

	/**
	 * Makes the current user a menu agent, and returns its ID.
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
	 * A classic menu with two visible custom links, Alpha and Bravo, and a draft page. Returns the menu and the three IDs.
	 *
	 * @return array{menu: int, a: int, b: int, draft: int}
	 */
	private function menu_with_draft(): array {
		$menu  = $this->classic_menu( 'Bracket ' . wp_generate_password( 6, false ) );
		$a     = $this->custom_item( $menu, 'Alpha', 'https://example.com/a', 0, 1 );
		$b     = $this->custom_item( $menu, 'Bravo', 'https://example.com/b', 0, 2 );
		$draft = $this->admin_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Draft page',
			)
		);

		return array(
			'menu'  => $menu,
			'a'     => $a,
			'b'     => $b,
			'draft' => $draft,
		);
	}

	/**
	 * A call that hides Bravo.
	 *
	 * @param int $menu The menu.
	 * @param int $item The item.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function hide( int $menu, int $item ): mixed {
		return $this->execute_ability(
			self::ABILITY,
			array(
				'kind' => 'classic',
				'menu' => $menu,
				'op'   => 'hide',
				'item' => $item,
			)
		);
	}

	/**
	 * The capability answers a get-menu filter would see for the agent: edit on a menu item, assign on the menu, and edit on a draft post.
	 *
	 * @param int $menu  The menu.
	 * @param int $item  A menu item.
	 * @param int $draft A draft post.
	 * @return array{edit_item: bool, assign: bool, edit_draft: bool}
	 */
	private function answers( int $menu, int $item, int $draft ): array {
		return array(
			'edit_item'  => current_user_can( 'edit_post', $item ),
			'assign'     => current_user_can( 'assign_term', $menu ),
			'edit_draft' => current_user_can( 'edit_post', $draft ),
		);
	}

	/**
	 * (a) Filters that get-menu runs see no write grant, and a control probe on a core hook during the same call does. The probe is on
	 * wp_update_nav_menu, which the writer fires after its change, so the grant is there for it.
	 */
	public function test_get_menu_filters_see_no_write_grant_and_a_control_probe_does(): void {
		$fixture = $this->menu_with_draft();
		$this->menu_agent();
		$seen = array();

		$this->on(
			'wp_get_nav_menu_items',
			function ( array $items ) use ( &$seen, $fixture ): array {
				$seen['filter'] = $this->answers( $fixture['menu'], $fixture['b'], $fixture['draft'] );

				return $items;
			},
			10,
			3
		);
		$this->on(
			'wp_setup_nav_menu_item',
			function ( mixed $item ) use ( &$seen, $fixture ): mixed {
				$seen['setup'] = $this->answers( $fixture['menu'], $fixture['b'], $fixture['draft'] );

				return $item;
			}
		);
		$this->on(
			'wp_update_nav_menu',
			function () use ( &$seen, $fixture ): void {
				$seen['control'] = $this->answers( $fixture['menu'], $fixture['b'], $fixture['draft'] );
			},
			10,
			2
		);

		$result = $this->hide( $fixture['menu'], $fixture['b'] );

		$this->assertTrue( $result['changed'] );
		foreach ( array( 'filter', 'setup' ) as $label ) {
			$this->assertArrayHasKey( $label, $seen, "The $label filter must run." );
			$this->assertSame(
				array(
					'edit_item'  => false,
					'assign'     => false,
					'edit_draft' => false,
				),
				$seen[ $label ],
				"A $label filter in get-menu's read must see no write grant."
			);
		}
		$this->assertArrayHasKey( 'control', $seen, 'The control probe must run.' );
		$this->assertTrue( $seen['control']['edit_item'], 'The control probe is inside the write grant: edit_post on the item.' );
		$this->assertTrue( $seen['control']['assign'], 'The control probe is inside the write grant: assign_term on the menu.' );
	}

	/**
	 * (b), (c), (d) and (e) on a core hook during a write. Inside the bracket the grant is off and the writers refuse; after it, the grant
	 * is back; a throw inside it restores the state; and a writer called inside it throws its LogicException.
	 */
	public function test_the_bracket_restores_the_state_and_the_writers_refuse_inside_it(): void {
		$fixture = $this->menu_with_draft();
		$this->menu_agent();
		$record = array();

		$this->on(
			'wp_update_nav_menu',
			function () use ( &$record, $fixture ): void {
				$record['inside']  = Pipeline::as_read( fn(): bool => current_user_can( 'edit_post', $fixture['b'] ) );
				$record['after']   = current_user_can( 'edit_post', $fixture['b'] );
				$record['running'] = Pipeline::is_running() && Pipeline::is_write_call() && Pipeline::is_writing();

				try {
					Pipeline::as_read(
						static function (): void {
							throw new \RuntimeException( 'Thrown inside the bracket.' );
						}
					);
				} catch ( \RuntimeException ) {
					$record['thrown'] = true;
				}
				$record['restored'] = Pipeline::is_write_call() && Pipeline::is_writing() && current_user_can( 'edit_post', $fixture['b'] );

				try {
					Pipeline::as_read(
						static fn() => MenuWriter::rename( $fixture['b'], wp_get_nav_menu_object( $fixture['menu'] ), 'Refused', array(), static fn(): bool => true )
					);
				} catch ( \LogicException $error ) {
					$record['writer'] = $error->getMessage();
				}
			},
			10,
			2
		);

		$result = $this->hide( $fixture['menu'], $fixture['b'] );

		$this->assertTrue( $result['changed'], 'The write still succeeds after the bracket.' );
		$this->assertFalse( $record['inside'], 'Inside the bracket, the write grant is off.' );
		$this->assertTrue( $record['after'], 'After the bracket, the write grant is back.' );
		$this->assertTrue( $record['running'], 'Outside the bracket, the call is a write call, writing.' );
		$this->assertTrue( $record['thrown'] ?? false, 'The throw reached the caller.' );
		$this->assertTrue( $record['restored'], 'A throw inside the bracket restores the write state.' );
		$this->assertArrayHasKey( 'writer', $record, 'A writer inside the bracket must throw.' );
		$this->assertStringContainsString( 'MenuWriter may only be called from a write ability', $record['writer'] );
		$this->assertSame( 'draft', get_post( $fixture['b'] )->post_status, 'The hide really happened.' );
	}

	/**
	 * (e) Outside any Steadhand call the bracket only runs its work: nothing about the pipeline changes.
	 */
	public function test_the_bracket_outside_a_call_changes_nothing(): void {
		$before = array( Pipeline::is_running(), Pipeline::is_write_call(), Pipeline::is_writing() );

		$value = Pipeline::as_read( static fn(): string => 'ran' );

		$this->assertSame( 'ran', $value );
		$this->assertSame( $before, array( Pipeline::is_running(), Pipeline::is_write_call(), Pipeline::is_writing() ) );
		$this->assertFalse( Pipeline::is_running() );
	}

	/**
	 * L1, before the write: a filter that throws on get-menu's read does not fail the write. The write is logged ok and changed; the
	 * previous position is 0, and the warning says so. The message must not say that the change may be partly applied.
	 */
	public function test_a_throwing_filter_before_the_write_does_not_fail_the_write(): void {
		$fixture = $this->menu_with_draft();
		$agent   = $this->menu_agent();
		$this->on(
			'wp_get_nav_menu_items',
			static function (): array {
				throw new \RuntimeException( 'A broken filter.' );
			},
			10,
			3
		);

		$result = $this->hide( $fixture['menu'], $fixture['b'] );

		$this->assertIsArray( $result, 'The write must not fail.' );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 0, $result['previous']['order'] );
		$this->assertSame( 0, $result['item']['order'] );
		$this->assertContains( self::UNLISTED, $result['warnings'] );
		$this->assertSame( 'draft', get_post( $fixture['b'] )->post_status, 'The row really changed.' );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'], 'The write is logged ok.' );
	}

	/**
	 * L1, after the write: a filter that throws once the menu has been written does not fail the write either. The position is 0 with the
	 * warning; the previous position was read before the write and is kept.
	 */
	public function test_a_throwing_filter_after_the_write_does_not_fail_the_write(): void {
		$fixture = $this->menu_with_draft();
		$agent   = $this->menu_agent();
		$written = false;
		$this->on(
			'wp_update_nav_menu',
			static function () use ( &$written ): void {
				$written = true;
			},
			10,
			2
		);
		$this->on(
			'wp_get_nav_menu_items',
			static function ( array $items ) use ( &$written ): array {
				if ( $written ) {
					throw new \RuntimeException( 'A broken filter.' );
				}

				return $items;
			},
			10,
			3
		);

		$result = $this->hide( $fixture['menu'], $fixture['b'] );

		$this->assertIsArray( $result, 'The write must not fail.' );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 2, $result['previous']['order'], 'The previous position was read before the write.' );
		$this->assertSame( 0, $result['item']['order'] );
		$this->assertContains( self::UNLISTED, $result['warnings'] );
		$this->assertSame( 'draft', get_post( $fixture['b'] )->post_status, 'The row really changed.' );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'], 'The write is logged ok.' );
	}

	/**
	 * L2: a filter that drops the item from get-menu's list. The item has position 0, the warning says why, and the write is ok.
	 */
	public function test_a_filter_that_drops_the_item_gives_position_zero_and_the_warning(): void {
		$fixture = $this->menu_with_draft();
		$agent   = $this->menu_agent();
		$this->on(
			'wp_get_nav_menu_items',
			static function ( array $items ) use ( $fixture ): array {
				return array_values(
					array_filter(
						$items,
						static fn( \WP_Post $item ): bool => $item->ID !== $fixture['b']
					)
				);
			},
			10,
			3
		);

		$result = $this->hide( $fixture['menu'], $fixture['b'] );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 0, $result['item']['order'] );
		$this->assertSame( 0, $result['previous']['order'] );
		$this->assertContains( self::UNLISTED, $result['warnings'] );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'] );
	}
}
