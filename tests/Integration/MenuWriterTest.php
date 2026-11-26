<?php
/**
 * Tests for MenuWriter, the only place a classic menu item is changed. Most tests run the writer inside a real write (in_write()),
 * as a dedicated agent, and check the stored rows, not the return values alone.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\MenuRules;
use Steadhand\Pipeline\MenuWriter;

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
}
