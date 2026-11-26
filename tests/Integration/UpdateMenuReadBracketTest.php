<?php
/**
 * Tests for the read bracket update-menu uses for get-menu's reads (Pipeline::as_read()). The reads run third-party filters, so those
 * filters must not see the write grant, before the write or after it. A throw from them must not fail a completed write, and an item
 * get-menu does not list gets the position 0 with a warning that names the read that missed.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\MenuWriter;
use Steadhand\Pipeline\Pipeline;

/**
 * Each test calls update-menu as a dedicated agent with steadhand_menus and steadhand_publish.
 */
final class UpdateMenuReadBracketTest extends MenuWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * The warning for the position after the change, when get-menu does not list the item.
	 */
	private const ITEM_UNLISTED = 'get-menu does not list this item (for example its linked object is trashed or missing, or other code on the site leaves it out), so its position is given as 0.';

	/**
	 * The warning for the position before the change, when get-menu did not list the item.
	 */
	private const PREVIOUS_UNLISTED = 'get-menu did not list this item before the change (for example its linked object is trashed or missing, or other code on the site leaves it out), so its previous position is given as 0.';

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
	 * A classic menu with Alpha and Bravo (custom links), and Linked, a link to a published page, at positions 1, 2 and 3. A draft page is
	 * made as well. Returns the menu, the items and the draft page.
	 *
	 * @return array{menu: int, a: int, b: int, page_item: int, draft: int}
	 */
	private function menu_with_draft(): array {
		$menu = $this->classic_menu( 'Bracket ' . wp_generate_password( 6, false ) );
		$a    = $this->custom_item( $menu, 'Alpha', 'https://example.com/a', 0, 1 );
		$b    = $this->custom_item( $menu, 'Bravo', 'https://example.com/b', 0, 2 );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Linked page',
			)
		);
		$item = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page,
				'menu-item-title'     => 'Linked',
				'menu-item-status'    => 'publish',
				'menu-item-position'  => 3,
			)
		);
		$this->assertIsInt( $item, 'The linked item fixture must be created.' );
		$draft = $this->admin_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Draft page',
			)
		);

		return array(
			'menu'      => $menu,
			'a'         => $a,
			'b'         => $b,
			'page_item' => $item,
			'draft'     => $draft,
		);
	}

	/**
	 * A call of update-menu with one operation on one item.
	 *
	 * @param int    $menu The menu.
	 * @param string $op   The operation.
	 * @param int    $item The item.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call( int $menu, string $op, int $item ): mixed {
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
	 * The capability answers a filter would see for the agent: edit on a menu item, assign on the menu, and edit on a draft post.
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
	 * The number of frames on Pipeline's call stack, read by reflection.
	 */
	private function call_depth(): int {
		$property = new \ReflectionProperty( Pipeline::class, 'calls' );

		return count( (array) $property->getValue() );
	}

	/**
	 * Every observation a filter makes during a hide of the linked item is grant-free: each filter that runs in get-menu's reads and
	 * in the item read back, before the write and after it, sees no edit, assign or write grant. The probes append, so every single
	 * observation is checked. The control probe, on a core hook during the same call, sees the grant, which shows the probe can see it.
	 * The test fails if any observation is granted, and if there are no observations on one side of the write.
	 */
	public function test_every_observation_during_a_call_is_grant_free_and_the_write_is_seen_on_both_sides(): void {
		$fixture = $this->menu_with_draft();
		$this->menu_agent();
		$written      = false;
		$observations = array();
		$control      = array();
		$probe        = function ( string $hook ) use ( &$observations, &$written, $fixture ): void {
			$observations[] = array(
				'hook'    => $hook,
				'written' => $written,
				'answers' => $this->answers( $fixture['menu'], $fixture['page_item'], $fixture['draft'] ),
			);
		};

		$this->on(
			'wp_get_nav_menu_items',
			function ( array $items ) use ( $probe ): array {
				$probe( 'wp_get_nav_menu_items' );

				return $items;
			},
			10,
			3
		);
		$this->on(
			'wp_setup_nav_menu_item',
			function ( mixed $item ) use ( $probe ): mixed {
				$probe( 'wp_setup_nav_menu_item' );

				return $item;
			}
		);
		$this->on(
			'the_title',
			function ( mixed $title ) use ( $probe ): mixed {
				$probe( 'the_title' );

				return $title;
			},
			10,
			2
		);
		$this->on(
			'nav_menu_attr_title',
			function ( mixed $attr ) use ( $probe ): mixed {
				$probe( 'nav_menu_attr_title' );

				return $attr;
			}
		);
		$this->on(
			'nav_menu_description',
			function ( mixed $description ) use ( $probe ): mixed {
				$probe( 'nav_menu_description' );

				return $description;
			}
		);
		$this->on(
			'wp_update_nav_menu',
			function () use ( &$written, &$control, $fixture ): void {
				$written   = true;
				$control[] = $this->answers( $fixture['menu'], $fixture['page_item'], $fixture['draft'] );
			},
			10,
			2
		);

		$result = $this->call( $fixture['menu'], 'hide', $fixture['page_item'] );

		$this->assertTrue( $result['changed'] );
		$hooks = array_column( $observations, 'hook' );
		$this->assertContains( 'the_title', $hooks, 'The linked item must reach the title filter, so the probe is meaningful.' );

		$grant_free = array(
			'edit_item'  => false,
			'assign'     => false,
			'edit_draft' => false,
		);
		foreach ( $observations as $observation ) {
			$this->assertSame(
				$grant_free,
				$observation['answers'],
				"The {$observation['hook']} filter saw the write grant (written: " . ( $observation['written'] ? 'yes' : 'no' ) . ')'
			);
		}

		$before = array_filter( $observations, static fn( array $o ): bool => ! $o['written'] );
		$after  = array_filter( $observations, static fn( array $o ): bool => $o['written'] );
		$this->assertNotEmpty( $before, 'There must be at least one observation before the write.' );
		$this->assertNotEmpty( $after, 'There must be at least one observation after the write.' );

		$this->assertNotEmpty( $control, 'The control probe must run during the write.' );
		$this->assertTrue( $control[0]['edit_item'], 'The control probe sees the write grant on the item.' );
		$this->assertTrue( $control[0]['assign'], 'The control probe sees the write grant on the menu.' );
	}

	/**
	 * (b), (c), (d) on a core hook during a write. Inside the bracket the grant is off; after it, the grant is back; a throw inside it
	 * restores the state; and a writer called inside it throws its LogicException.
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

		$result = $this->call( $fixture['menu'], 'hide', $fixture['b'] );

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
	 * Outside any Steadhand call the bracket only runs its work: nothing about the pipeline changes.
	 */
	public function test_the_bracket_outside_a_call_changes_nothing(): void {
		$before = array( Pipeline::is_running(), Pipeline::is_write_call(), Pipeline::is_writing(), $this->call_depth() );

		$value = Pipeline::as_read( static fn(): string => 'ran' );

		$this->assertSame( 'ran', $value );
		$this->assertSame( $before, array( Pipeline::is_running(), Pipeline::is_write_call(), Pipeline::is_writing(), $this->call_depth() ) );
		$this->assertFalse( Pipeline::is_running() );
	}

	/**
	 * Inside a read call the bracket is a pass-through: there is no write to narrow, so it pushes no frame. Inside a write call it pushes one,
	 * which is what narrows the state. Both are checked by the depth of Pipeline's call stack.
	 */
	public function test_the_bracket_is_a_pass_through_inside_a_read_call_and_narrows_inside_a_write(): void {
		$fixture = $this->menu_with_draft();
		$this->menu_agent();
		$depths = array();

		// A read call: get-menu runs its filter inside its own call, which is a read frame.
		$this->on(
			'wp_get_nav_menu_items',
			function ( array $items ) use ( &$depths ): array {
				$outer          = $this->call_depth();
				$inside         = Pipeline::as_read( fn(): int => $this->call_depth() );
				$depths['read'] = array( $outer, $inside );

				return $items;
			},
			10,
			3
		);
		$this->execute_ability(
			'steadhand/get-menu',
			array(
				'kind' => 'classic',
				'id'   => $fixture['menu'],
			)
		);

		$this->assertSame( array( $depths['read'][0], $depths['read'][0] ), $depths['read'], 'A bracket inside a read call pushes no frame.' );
		$this->assertGreaterThan( 0, $depths['read'][0] );

		// A write call: the bracket pushes one frame.
		$this->on(
			'wp_update_nav_menu',
			function () use ( &$depths ): void {
				$outer           = $this->call_depth();
				$inside          = Pipeline::as_read( fn(): int => $this->call_depth() );
				$depths['write'] = array( $outer, $inside );
			},
			10,
			2
		);
		$this->menu_agent();
		$this->call( $fixture['menu'], 'hide', $fixture['b'] );

		$this->assertSame( $depths['write'][0] + 1, $depths['write'][1], 'A bracket inside a write call pushes one read frame.' );
	}

	/**
	 * Before the change, a filter that throws does not fail the write. The write is logged ok and changed, the row changed, and the two
	 * positions and their warnings say what was missed: the previous position and the item's position are both 0.
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

		$result = $this->call( $fixture['menu'], 'hide', $fixture['b'] );

		$this->assertIsArray( $result, 'The write must not fail.' );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 0, $result['previous']['order'] );
		$this->assertSame( 0, $result['item']['order'] );
		$this->assertContains( self::PREVIOUS_UNLISTED, $result['warnings'] );
		$this->assertContains( self::ITEM_UNLISTED, $result['warnings'] );
		$this->assertSame( 'draft', get_post( $fixture['b'] )->post_status, 'The row really changed.' );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'], 'The write is logged ok.' );
	}

	/**
	 * After the change, a filter that throws does not fail the write either. The previous position was read before the change and is kept;
	 * the item's position is 0 with its own warning, and no previous warning.
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

		$result = $this->call( $fixture['menu'], 'hide', $fixture['b'] );

		$this->assertIsArray( $result, 'The write must not fail.' );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 2, $result['previous']['order'], 'The previous position was read before the write.' );
		$this->assertSame( 0, $result['item']['order'] );
		$this->assertContains( self::ITEM_UNLISTED, $result['warnings'] );
		$this->assertNotContains( self::PREVIOUS_UNLISTED, $result['warnings'] );
		$this->assertSame( 'draft', get_post( $fixture['b'] )->post_status, 'The row really changed.' );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'], 'The write is logged ok.' );
	}

	/**
	 * A filter that drops draft items from get-menu's list. On show, the item was a draft before the change, so only the previous position
	 * is missed: previous.order is 0 with its warning, and item.order is the real position with no item warning.
	 */
	public function test_show_warns_only_about_the_previous_position_when_a_filter_drops_drafts(): void {
		$fixture = $this->menu_with_draft();
		$this->menu_agent();
		$this->assertTrue( $this->call( $fixture['menu'], 'hide', $fixture['b'] )['changed'] );
		$this->drop_drafts();

		$result = $this->call( $fixture['menu'], 'show', $fixture['b'] );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 0, $result['previous']['order'], 'The item was a draft before the change, so get-menu did not list it.' );
		$this->assertSame( 2, $result['item']['order'], 'The item is listed after the change, at its real position.' );
		$this->assertContains( self::PREVIOUS_UNLISTED, $result['warnings'] );
		$this->assertNotContains( self::ITEM_UNLISTED, $result['warnings'] );
	}

	/**
	 * The mirror: on hide, the item was listed before the change, and the drop of drafts removes it after. Only the item's position warning
	 * is given, and previous.order is the real position.
	 */
	public function test_hide_warns_only_about_the_item_when_a_filter_drops_drafts(): void {
		$fixture = $this->menu_with_draft();
		$this->menu_agent();
		$this->drop_drafts();

		$result = $this->call( $fixture['menu'], 'hide', $fixture['b'] );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 2, $result['previous']['order'], 'The item was listed before the change, at its real position.' );
		$this->assertSame( 0, $result['item']['order'], 'The hidden item is dropped by the filter, so it has no position.' );
		$this->assertContains( self::ITEM_UNLISTED, $result['warnings'] );
		$this->assertNotContains( self::PREVIOUS_UNLISTED, $result['warnings'] );
	}

	/**
	 * Adds a get-menu filter that drops every draft item.
	 */
	private function drop_drafts(): void {
		$this->on(
			'wp_get_nav_menu_items',
			static function ( array $items ): array {
				return array_values(
					array_filter(
						$items,
						static fn( \WP_Post $item ): bool => 'publish' === $item->post_status
					)
				);
			},
			10,
			3
		);
	}
}
