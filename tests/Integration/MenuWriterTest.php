<?php
/**
 * Tests for MenuWriter, the only place a classic menu item is changed. Most tests run the writer inside a real write (in_write()),
 * as a dedicated agent, and check the stored rows, not the return values alone.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\MenuRules;
use Steadhand\Pipeline\MenuWriter;
use Steadhand\Pipeline\Pipeline;

/**
 * Negative tests come first: a call outside a write, a change made between the read and the write, a listener that changes the row,
 * and a caller whose check fails. The positive tests check the round trips.
 */
final class MenuWriterTest extends MenuWriteTestCase {

	/**
	 * How many times wp_update_nav_menu fired, counted by a listener the test adds.
	 *
	 * @var int
	 */
	private int $announced = 0;

	/**
	 * A custom link item, published, in a fresh menu. Returns the menu term ID and the item ID.
	 *
	 * @param string $title The item's title.
	 * @param string $url   The item's URL.
	 * @return array{0: int, 1: int}
	 */
	private function menu_with_item( string $title = 'Plain title', string $url = 'https://example.com/a' ): array {
		$menu = $this->classic_menu( 'Writer ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, $title, $url );

		return array( $menu, $item );
	}

	/**
	 * The writer's state of an item, read by the rules: the status and title, as a caller reads them.
	 *
	 * @param int $item_id Item post ID.
	 * @return array<string, mixed>
	 */
	private function read_state( int $item_id ): array {
		$state = MenuRules::fresh_state( $item_id );
		$this->assertIsArray( $state, 'The item must read back as a menu item.' );

		return $state;
	}

	/**
	 * Counts the menu announcements that happen inside a call.
	 */
	private function count_announcements(): void {
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
	 * A call of MenuWriter::set_status() outside any write throws, and nothing changes.
	 */
	public function test_set_status_throws_outside_a_write(): void {
		[ $menu, $item ] = $this->menu_with_item();

		$this->expectException( \LogicException::class );
		MenuWriter::set_status( $item, $this->menu_term( $menu ), 'draft', array(), static fn(): bool => true );
	}

	/**
	 * A call of MenuWriter::rename() outside any write throws.
	 */
	public function test_rename_throws_outside_a_write(): void {
		[ $menu, $item ] = $this->menu_with_item();

		$this->expectException( \LogicException::class );
		MenuWriter::rename( $item, $this->menu_term( $menu ), 'Renamed', array(), static fn(): bool => true );
	}

	/**
	 * A call of MenuWriter::add() outside any write throws.
	 */
	public function test_add_throws_outside_a_write(): void {
		[ $menu ] = $this->menu_with_item();

		$this->expectException( \LogicException::class );
		MenuWriter::add(
			$this->menu_term( $menu ),
			array(
				'type'      => 'custom',
				'object'    => 'custom',
				'object_id' => 0,
				'url'       => 'https://example.com/b',
				'title'     => 'Added',
				'parent'    => 0,
				'position'  => 2,
			),
			array(
				'count'     => 1,
				'max_order' => 1,
			),
			static fn(): bool => true
		);
	}

	/**
	 * A status change made after the caller read the item is refused, and nothing is written.
	 */
	public function test_a_change_made_between_read_and_write_is_refused_as_content_changed(): void {
		[ $menu, $item ] = $this->menu_with_item();
		$term            = $this->menu_term( $menu );
		$stale           = $this->read_state( $item );
		$stale['status'] = 'draft'; // The item is published. The caller read it as a draft, which is what a change made in between looks like.

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $term, 'draft', $stale, static fn(): bool => true )
		);

		$this->assert_refused( $result, 'steadhand_refused_content_changed' );
		$this->assertSame( 'publish', $this->raw_row( $item )->post_status, 'A refused change must leave the item as it was.' );
	}

	/**
	 * A rename made after the caller read the item is refused, and nothing is written.
	 */
	public function test_a_rename_made_between_read_and_write_is_refused_as_content_changed(): void {
		[ $menu, $item ] = $this->menu_with_item( 'Plain title' );
		$term            = $this->menu_term( $menu );
		$stale           = $this->read_state( $item );
		$stale['title']  = 'Somebody else changed this';

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::rename( $item, $term, 'Mine', $stale, static fn(): bool => true )
		);

		$this->assert_refused( $result, 'steadhand_refused_content_changed' );
		$this->assertSame( 'Plain title', $this->raw_row( $item )->post_title, 'A refused rename must leave the title as it was.' );
	}

	/**
	 * A caller whose check fails on the fresh item gets the generic refusal, and nothing is written.
	 */
	public function test_a_caller_check_that_fails_on_the_fresh_item_changes_nothing(): void {
		[ $menu, $item ] = $this->menu_with_item();
		$term            = $this->menu_term( $menu );
		$state           = $this->read_state( $item );

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $term, 'draft', $state, static fn(): bool => false )
		);

		$this->assert_generic( $result );
		$this->assertSame( 'publish', $this->raw_row( $item )->post_status );
		$this->assertSame( 0, $this->rows_of( $item, MenuRules::HIDDEN_META ), 'A refused hide must store no hidden key.' );
	}

	/**
	 * An item that belongs to another menu is refused with the generic text, whatever the caller checked.
	 */
	public function test_an_item_of_another_menu_is_refused_with_the_generic_text(): void {
		$item  = $this->menu_with_item()[1];
		$other = $this->classic_menu( 'Other ' . wp_generate_password( 6, false ) );
		$state = $this->read_state( $item );

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $this->menu_term( $other ), 'draft', $state, static fn(): bool => true )
		);

		$this->assert_generic( $result );
		$this->assertSame( 'publish', $this->raw_row( $item )->post_status, 'The item of another menu must not be touched.' );
	}

	/**
	 * A listener that changes the item's status during a rename is reported as partial, and the change is not called a success.
	 */
	public function test_a_listener_that_changes_the_row_is_reported_as_partial(): void {
		[ $menu, $item ] = $this->menu_with_item();
		$term            = $this->menu_term( $menu );
		$state           = $this->read_state( $item );

		// A listener that saves the item as a draft while the rename is being saved.
		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ) use ( $item ): array {
				if ( (int) ( $postarr['ID'] ?? 0 ) === $item ) {
					$data['post_status'] = 'draft';
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::rename( $item, $term, 'Renamed', $state, static fn(): bool => true )
		);

		$this->assert_refused( $result, 'steadhand_update_partial' );
	}

	/**
	 * Hiding an item stores its three hidden keys, and showing it publishes it again and removes them. Each write fires the
	 * menu-changed action once.
	 */
	public function test_hide_then_show_round_trips_the_item_and_its_keys(): void {
		[ $menu, $item ] = $this->menu_with_item();
		$term            = $this->menu_term( $menu );
		$this->count_announcements();

		$hidden = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $term, 'draft', $this->read_state( $item ), static fn(): bool => true )
		);
		$this->assertSame(
			array(
				'id'      => $item,
				'changed' => true,
			),
			$hidden
		);
		$this->assertSame( 'draft', $this->raw_row( $item )->post_status );
		$this->assertTrue( MenuRules::hide_recorded( $item ), 'The hide must leave the three keys, each with one row.' );

		$shown = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $term, 'publish', $this->read_state( $item ), static fn(): bool => true )
		);
		$this->assertSame(
			array(
				'id'      => $item,
				'changed' => true,
			),
			$shown
		);
		$this->assertSame( 'publish', $this->raw_row( $item )->post_status );
		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $item, $key ), "Show must remove $key." );
		}

		$this->assertSame( 2, $this->announced, 'Each change fires wp_update_nav_menu once.' );
	}

	/**
	 * Hiding and showing an item keep the administrator's title markup byte for byte. Core's save would strip an <i> class, and the
	 * keep-text guard stops that.
	 */
	public function test_hide_and_show_keep_an_administrators_title_markup_byte_for_byte(): void {
		wp_set_current_user( $this->admin_id() );
		[ $menu, $item ] = $this->menu_with_item( '<i class="fa-home">Home</i>' );
		$stored          = $this->raw_row( $item )->post_title;
		$this->assertStringContainsString( 'class="fa-home"', $stored, 'The fixture must hold the markup before the agent acts.' );
		$term = $this->menu_term( $menu );

		$this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $term, 'draft', $this->read_state( $item ), static fn(): bool => true )
		);
		$this->assertSame( $stored, $this->raw_row( $item )->post_title, 'The hide must keep the title as stored.' );

		$this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $term, 'publish', $this->read_state( $item ), static fn(): bool => true )
		);
		$this->assertSame( $stored, $this->raw_row( $item )->post_title, 'The show must keep the title as stored.' );
	}

	/**
	 * A rename changes the title and keeps the content and the excerpt, whatever the agent's own save filters would do to them.
	 */
	public function test_rename_changes_the_title_and_keeps_the_content_and_the_excerpt(): void {
		wp_set_current_user( $this->admin_id() );
		[ $menu, $item ] = $this->menu_with_item();
		wp_update_post(
			array(
				'ID'           => $item,
				'post_content' => '<i class="keep-me">kept</i>',
				'post_excerpt' => '<i class="keep-me">excerpt</i>',
			)
		);
		$before = $this->raw_row( $item );

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::rename( $item, $this->menu_term( $menu ), 'Renamed', $this->read_state( $item ), static fn(): bool => true )
		);

		$this->assertSame(
			array(
				'id'      => $item,
				'changed' => true,
			),
			$result
		);
		$after = $this->raw_row( $item );
		$this->assertSame( 'Renamed', $after->post_title );
		$this->assertSame( $before->post_content, $after->post_content, 'The content must be kept as stored.' );
		$this->assertSame( $before->post_excerpt, $after->post_excerpt, 'The excerpt must be kept as stored.' );
	}

	/**
	 * An item hidden by Steadhand can be renamed and then shown: the rename refreshes the modified key, so show still recognises it.
	 */
	public function test_a_rename_of_a_hidden_item_keeps_it_showable(): void {
		[ $menu, $item ] = $this->menu_with_item();
		$term            = $this->menu_term( $menu );

		$this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $term, 'draft', $this->read_state( $item ), static fn(): bool => true )
		);
		$this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::rename( $item, $term, 'Renamed while hidden', $this->read_state( $item ), static fn(): bool => true )
		);

		$this->assertTrue( MenuRules::hide_recorded( $item ), 'A rename must refresh the modified key the hide left.' );
		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $item, $term, 'publish', $this->read_state( $item ), static fn(): bool => true )
		);
		$this->assertSame(
			array(
				'id'      => $item,
				'changed' => true,
			),
			$result
		);
	}

	/**
	 * A rename with the same title changes nothing and says so.
	 */
	public function test_a_rename_to_the_same_title_reports_no_change(): void {
		[ $menu, $item ] = $this->menu_with_item( 'Same' );
		$this->count_announcements();

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::rename( $item, $this->menu_term( $menu ), 'Same', $this->read_state( $item ), static fn(): bool => true )
		);

		$this->assertSame(
			array(
				'id'      => $item,
				'changed' => false,
			),
			$result
		);
		$this->assertSame( 0, $this->announced, 'A change that changes nothing must not announce the menu.' );
	}

	/**
	 * An added item is stored as a hidden draft, then published, with its link and its position, and the menu is announced once.
	 */
	public function test_add_stores_the_item_then_publishes_it(): void {
		[ $menu, $first ] = $this->menu_with_item();
		$this->count_announcements();
		$term = $this->menu_term( $menu );

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::add(
				$term,
				array(
					'type'      => 'custom',
					'object'    => 'custom',
					'object_id' => 0,
					'url'       => 'https://example.com/new',
					'title'     => 'Added',
					'parent'    => 0,
					'position'  => 2,
				),
				array(
					'count'     => 1,
					'max_order' => $this->read_state( $first )['order'],
				),
				static fn( mixed $post ): bool => null === $post
			)
		);

		$this->assertIsArray( $result );
		$item = (int) $result['id'];
		$this->assertGreaterThan( 0, $item );
		$this->assertSame( 'publish', $this->raw_row( $item )->post_status );
		$this->assertSame( 'https://example.com/new', (string) get_post_meta( $item, '_menu_item_url', true ) );
		$this->assertSame( 'Added', $this->raw_row( $item )->post_title );
		$this->assertSame( 1, $this->announced );
		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $item, $key ), "Publishing the added item must remove $key." );
		}
	}

	/**
	 * An add refused because the menu changed since the caller read it creates nothing.
	 */
	public function test_an_add_refused_for_a_changed_menu_creates_no_item(): void {
		[ $menu ] = $this->menu_with_item();
		$before   = $this->item_count( $menu );

		$result = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::add(
				$this->menu_term( $menu ),
				array(
					'type'      => 'custom',
					'object'    => 'custom',
					'object_id' => 0,
					'url'       => 'https://example.com/new',
					'title'     => 'Added',
					'parent'    => 0,
					'position'  => 2,
				),
				array(
					'count'     => $before + 5,
					'max_order' => 1,
				),
				static fn(): bool => true
			)
		);

		$this->assert_refused( $result, 'steadhand_refused_content_changed' );
		$this->assertSame( $before, $this->item_count( $menu ), 'A refused add must create no row.' );
	}

	/**
	 * Runs a writer call inside a write as the agent, which becomes the current user when the test has not set one.
	 *
	 * @param callable $work The writer call.
	 * @return mixed
	 */
	private function act_as_agent_in_write( callable $work ): mixed {
		if ( 0 === get_current_user_id() || $this->admin_id() === get_current_user_id() ) {
			$this->act_as_agent();
		}

		return $this->in_write( \Closure::fromCallable( $work ) );
	}

	/**
	 * The number of nav_menu_item rows in a menu, in publish or draft.
	 *
	 * @param int $menu_id Menu term ID.
	 */
	private function item_count( int $menu_id ): int {
		return count( MenuRules::items_raw( $this->menu_term( $menu_id ) ) );
	}

	/**
	 * A menu with three published custom links, A, B and C, in that order, and their IDs.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int}
	 */
	private function menu_with_three(): array {
		$menu = $this->classic_menu( 'Move ' . wp_generate_password( 6, false ) );
		$a    = $this->custom_item( $menu, 'A', 'https://example.com/a', 0, 1 );
		$b    = $this->custom_item( $menu, 'B', 'https://example.com/b', 0, 2 );
		$c    = $this->custom_item( $menu, 'C', 'https://example.com/c', 0, 3 );

		return array( $menu, $a, $b, $c );
	}

	/**
	 * The plan and the states a caller would read for a move, built the way the ability builds them.
	 *
	 * @param int $menu   Menu term ID.
	 * @param int $item   Item.
	 * @param int $new_parent New parent, or 0.
	 * @param int $after  New predecessor, or 0.
	 * @return array{target: int[], orders: array<int, int>, states: array<int, array<string, mixed>>}
	 */
	private function plan_for( int $menu, int $item, int $new_parent, int $after ): array {
		$rows = MenuRules::items_raw( $this->menu_term( $menu ) );
		$plan = MenuRules::plan_move( $rows, $item, $new_parent, $after );
		$this->assertNull( $plan['refusal'], 'The test move must be a valid move.' );

		$ids = array_merge( array( $item ), array_keys( $plan['orders'] ) );
		if ( 0 !== $new_parent ) {
			$ids[] = $new_parent;
		}
		if ( 0 !== $after ) {
			$ids[] = $after;
		}

		$states = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row['id'], $ids, true ) ) {
				$states[ $row['id'] ] = array(
					'menu'   => $menu,
					'status' => $row['status'],
					'title'  => (string) $row['post']->post_title,
					'parent' => $row['parent'],
					'order'  => $row['order'],
				);
			}
		}

		return array(
			'target' => $plan['target'],
			'orders' => $plan['orders'],
			'states' => $states,
		);
	}

	/**
	 * Runs MenuWriter::move() inside a write as the agent, with the plan the caller read (or the given one).
	 *
	 * @param int        $menu     Menu term ID.
	 * @param int        $item     Item.
	 * @param int        $new_parent   New parent, or 0.
	 * @param int        $after    New predecessor, or 0.
	 * @param array|null $expected The plan the caller read, or null to read it now.
	 * @return mixed
	 */
	private function move_in_write( int $menu, int $item, int $new_parent, int $after, ?array $expected = null ): mixed {
		$expected ??= $this->plan_for( $menu, $item, $new_parent, $after );

		return $this->in_update_menu_write(
			fn(): mixed => MenuWriter::move( $this->menu_term( $menu ), $item, $new_parent, $after, $expected, static fn(): bool => true )
		);
	}

	/**
	 * Runs a writer call inside a write named steadhand/update-menu, as the agent, so the menu item mapping applies (the native checks of a move).
	 *
	 * @param callable $work The writer call.
	 * @return mixed
	 */
	private function in_update_menu_write( callable $work ): mixed {
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$result = ( new Pipeline( new SpyLogger() ) )->run(
			new ProbeAbility(
				Capabilities::MENUS,
				static fn(): array => array( 'answer' => $work() ),
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'steadhand/update-menu'
			),
			array()
		);

		$this->assertIsArray( $result, 'The probe call must be allowed to run.' );

		return $result['answer'];
	}

	/**
	 * The shape of a menu: each item's ID, parent and order, in flat order.
	 *
	 * @param int $menu Menu term ID.
	 * @return array<int, array{0: int, 1: int, 2: int}>
	 */
	private function shape_of( int $menu ): array {
		return array_map(
			static fn( array $row ): array => array( $row['id'], $row['parent'], $row['order'] ),
			MenuRules::items_raw( $this->menu_term( $menu ) )
		);
	}

	public function test_move_throws_outside_a_write(): void {
		[ $menu, $a, $b ] = $this->menu_with_three();
		$expected         = $this->plan_for( $menu, $b, 0, 0 );

		$this->expectException( \LogicException::class );
		MenuWriter::move( $this->menu_term( $menu ), $b, 0, 0, $expected, static fn(): bool => true );
	}


	public function test_a_change_made_between_read_and_write_is_refused_as_content_changed_and_nothing_is_written(): void {
		[ $menu, $a, $b, $c ] = $this->menu_with_three();
		$expected             = $this->plan_for( $menu, $c, 0, 0 );
		$before               = $this->shape_of( $menu );

		// Another request hides A after the read, so A's state no longer matches what the caller read.
		wp_update_post(
			array(
				'ID'          => $a,
				'post_status' => 'draft',
			)
		);

		$result = $this->move_in_write( $menu, $c, 0, 0, $expected );

		$this->assert_refused( $result, 'steadhand_refused_content_changed' );
		$this->assertSame( $before, $this->shape_of( $menu ), 'Nothing is written when the state changed.' );
		$this->assertSame( 0, (int) get_post_meta( $c, MenuRules::PARENT_META, true ), 'The parent meta of C is the one it had.' );
	}


	public function test_an_item_added_after_the_read_changes_the_plan_and_nothing_is_written(): void {
		[ $menu, $a, $b, $c ] = $this->menu_with_three();
		$expected             = $this->plan_for( $menu, $c, 0, 0 );

		// A new item at the end is in no state the caller read, but it changes the whole-menu order the plan was made from.
		$new    = $this->custom_item( $menu, 'D', 'https://example.com/d', 0, 4 );
		$before = $this->shape_of( $menu );

		$result = $this->move_in_write( $menu, $c, 0, 0, $expected );

		$this->assert_refused( $result, 'steadhand_refused_content_changed' );
		$this->assertSame( $before, $this->shape_of( $menu ), 'Nothing is written.' );
		$this->assertContains( $new, array_column( MenuRules::items_raw( $this->menu_term( $menu ) ), 'id' ) );
	}

	public function test_a_move_to_where_the_item_already_is_changes_nothing_and_fires_nothing(): void {
		[ $menu, $a, $b ] = $this->menu_with_three();
		$this->count_announcements();
		$before = $this->shape_of( $menu );

		// B is already the second top-level item after A.
		$result = $this->move_in_write( $menu, $b, 0, $a );

		$this->assertSame(
			array(
				'id'      => $b,
				'changed' => false,
			),
			$result
		);
		$this->assertSame( $before, $this->shape_of( $menu ) );
		$this->assertSame( 0, $this->announced, 'A move that changes nothing announces nothing.' );
	}

	public function test_a_move_changes_only_the_parent_and_the_order_and_announces_once(): void {
		[ $menu, $a, $b, $c ] = $this->menu_with_three();
		$this->count_announcements();
		$texts = array();
		foreach ( array( $a, $b, $c ) as $id ) {
			$post         = $this->raw_row( $id );
			$texts[ $id ] = array( $post->post_title, $post->post_content, $post->post_excerpt, $post->post_status );
		}

		$result = $this->move_in_write( $menu, $c, 0, 0 );

		$this->assertSame(
			array(
				'id'      => $c,
				'changed' => true,
			),
			$result
		);
		$this->assertSame( array( $c, $a, $b ), array_column( MenuRules::items_raw( $this->menu_term( $menu ) ), 'id' ) );
		foreach ( array( $a, $b, $c ) as $id ) {
			$post = $this->raw_row( $id );
			$this->assertSame( $texts[ $id ], array( $post->post_title, $post->post_content, $post->post_excerpt, $post->post_status ), "Item $id keeps its text and status." );
		}
		$this->assertSame( 1, $this->announced, 'One move fires wp_update_nav_menu once.' );
	}

	public function test_a_listener_that_changes_an_order_write_is_reported_as_partial_and_no_item_is_lost(): void {
		[ $menu, $a, $b, $c ] = $this->menu_with_three();

		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ) use ( $a ): array {
				if ( (int) ( $postarr['ID'] ?? 0 ) === $a && isset( $postarr['menu_order'] ) ) {
					$data['menu_order'] = 999;
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->move_in_write( $menu, $c, 0, 0 );

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( (string) $c, $result->get_error_message(), 'The message names the moved item.' );
		$this->assertStringContainsString( 'get-menu', $result->get_error_message() );
		$this->assertCount( 3, MenuRules::items_raw( $this->menu_term( $menu ) ), 'No item is lost.' );
	}

	public function test_a_parent_hidden_in_the_gap_is_put_back_and_reported_as_partial(): void {
		[ $menu, $a, $b, $c ] = $this->menu_with_three();
		$child                = $this->custom_item( $menu, 'Child', 'https://example.com/child', 0, 4 );

		// The move puts the child under B, and another request hides B between the parent write and the check.
		$this->on(
			'updated_post_meta',
			static function ( mixed $meta_id, mixed $object_id, mixed $meta_key ) use ( $b, $child ): void {
				unset( $meta_id );
				if ( MenuRules::PARENT_META === $meta_key && (int) $object_id === $child ) {
					wp_update_post(
						array(
							'ID'          => $b,
							'post_status' => 'draft',
						)
					);
				}
			},
			10,
			3
		);

		$result = $this->move_in_write( $menu, $child, $b, 0 );

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( 'moved it back', $result->get_error_message() );
		$this->assertSame( 0, (int) get_post_meta( $child, MenuRules::PARENT_META, true ), 'The parent meta is back to the top level.' );
	}


	public function test_a_move_and_its_inverse_restore_the_exact_orders_and_parents(): void {
		// A has a child A1 placed right after it, so every subtree is next to each other, as Save Menu leaves them.
		$menu = $this->classic_menu( 'Round ' . wp_generate_password( 6, false ) );
		$a    = $this->custom_item( $menu, 'A', 'https://example.com/a', 0, 1 );
		$a1   = $this->custom_item( $menu, 'A1', 'https://example.com/a1', $a, 2 );
		$b    = $this->custom_item( $menu, 'B', 'https://example.com/b', 0, 3 );
		$c    = $this->custom_item( $menu, 'C', 'https://example.com/c', 0, 4 );
		$this->custom_item( $menu, 'D', 'https://example.com/d', 0, 5 );
		$before = $this->shape_of( $menu );

		// C goes under A after A1. Its old predecessor was B, at the top level.
		$this->move_in_write( $menu, $c, $a, $a1 );
		$this->assertNotSame( $before, $this->shape_of( $menu ) );

		$this->move_in_write( $menu, $c, 0, $b );

		$this->assertSame( $before, $this->shape_of( $menu ), 'The inverse restores every order and parent.' );
	}


	public function test_a_hidden_item_moved_keeps_its_hide_record_and_can_be_shown(): void {
		[ $menu, $a, $b ] = $this->menu_with_three();
		$term             = $this->menu_term( $menu );

		$this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $b, $term, 'draft', $this->read_state( $b ), static fn(): bool => true )
		);
		$this->assertTrue( MenuRules::hide_recorded( $b ) );

		// B is hidden and moves to the top: its order changes, so its modified time changes, and the record is refreshed.
		$result = $this->move_in_write( $menu, $b, 0, 0 );
		$this->assertSame(
			array(
				'id'      => $b,
				'changed' => true,
			),
			$result
		);
		$this->assertTrue( MenuRules::hide_recorded( $b ), 'The move keeps the hide record valid.' );

		$shown = $this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $b, $term, 'publish', $this->read_state( $b ), static fn(): bool => true )
		);
		$this->assertSame(
			array(
				'id'      => $b,
				'changed' => true,
			),
			$shown
		);
		$this->assertSame( 'publish', $this->raw_row( $b )->post_status );
	}

	public function test_a_stale_hide_record_is_not_rearmed_by_a_move(): void {
		[ $menu, $a, $b ] = $this->menu_with_three();
		$term             = $this->menu_term( $menu );

		$this->act_as_agent_in_write(
			fn(): mixed => MenuWriter::set_status( $b, $term, 'draft', $this->read_state( $b ), static fn(): bool => true )
		);

		// An edit outside Steadhand changes the modified time, so the hide record is stale before the move.
		wp_update_post(
			array(
				'ID'         => $b,
				'post_title' => 'Edited outside',
			)
		);
		update_post_meta( $b, MenuRules::HIDDEN_MODIFIED_META, '2001-01-01 00:00:00' );
		$this->assertFalse( MenuRules::hide_recorded( $b ) );

		$this->move_in_write( $menu, $b, 0, 0 );

		$this->assertFalse( MenuRules::hide_recorded( $b ), 'A move must not re-arm a stale record.' );
	}

	/**
	 * A put-back to an old parent that is hidden too leaves the item visible under a hidden parent, so Steadhand hides the moved item
	 * instead, as one correction, and reports partial.
	 */
	public function test_a_put_back_to_a_hidden_old_parent_hides_the_moved_item_instead(): void {
		[ $menu, $a, $b, $c ] = $this->menu_with_three();
		$q                    = $this->custom_item( $menu, 'Q', 'https://example.com/q', 0, 4 );
		$child                = $this->custom_item( $menu, 'X', 'https://example.com/x', $q, 5 );

		// In the gap between the parent write and the check, the new parent B and the old parent Q are both hidden.
		$this->on(
			'updated_post_meta',
			static function ( mixed $meta_id, mixed $object_id, mixed $meta_key ) use ( $b, $q, $child ): void {
				unset( $meta_id );
				if ( MenuRules::PARENT_META === $meta_key && (int) $object_id === $child ) {
					wp_update_post(
						array(
							'ID'          => $b,
							'post_status' => 'draft',
						)
					);
					wp_update_post(
						array(
							'ID'          => $q,
							'post_status' => 'draft',
						)
					);
				}
			},
			10,
			3
		);

		$result = $this->move_in_write( $menu, $child, $b, 0 );

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( 'hid the moved item', $result->get_error_message() );
		$this->assertSame( 'draft', $this->raw_row( $child )->post_status, 'The moved item is hidden.' );
		$this->assertTrue( MenuRules::hide_recorded( $child ), 'The hide is a Steadhand hide, with its record.' );
	}

	/**
	 * An exception after the first write is a partial failure that names the item, and the menu-changed action fires once.
	 */
	public function test_an_exception_after_a_write_is_partial_and_names_the_item(): void {
		[ $menu, $a, $b, $c ] = $this->menu_with_three();
		$this->count_announcements();

		// C goes first, so C is written, then A, then B; B's save throws.
		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ) use ( $b ): array {
				if ( (int) ( $postarr['ID'] ?? 0 ) === $b && isset( $postarr['menu_order'] ) ) {
					throw new \RuntimeException( 'A listener failed.' );
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->move_in_write( $menu, $c, 0, 0 );

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( (string) $c, $result->get_error_message() );
		$this->assertSame( 1, $this->announced, 'The menu-changed action fires once, because writes were made.' );
		$this->assertCount( 3, MenuRules::items_raw( $this->menu_term( $menu ) ), 'No item is lost.' );
	}
}
