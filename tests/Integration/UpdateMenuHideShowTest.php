<?php
/**
 * Tests for the hide and show operations of steadhand/update-menu. Negative tests come first: a hide that would promote a child, a
 * show of an item Steadhand did not hide or that changed since, and a show under a hidden parent or of an unpublished link.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\MenuRules;

/**
 * Each test runs the ability through the pipeline, as a dedicated agent with steadhand_menus and steadhand_publish, unless it says otherwise.
 */
final class UpdateMenuHideShowTest extends MenuWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * How many times wp_delete_post() ran.
	 *
	 * @var int
	 */
	private int $deletes = 0;

	/**
	 * How many times the menu was announced (wp_update_nav_menu fired).
	 *
	 * @var int
	 */
	private int $announced = 0;

	/**
	 * Counts permanent deletes and announcements, which the tests read.
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
			'wp_update_nav_menu',
			function (): void {
				++$this->announced;
			},
			10,
			2
		);
	}

	/**
	 * Checks that no permanent delete happened in the test.
	 */
	protected function tearDown(): void {
		$this->assertSame( 0, $this->deletes, 'update-menu must never call wp_delete_post().' );
		parent::tearDown();
	}

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
	 * A call of the ability with a menu, an operation and an item.
	 *
	 * @param int    $menu The menu.
	 * @param string $op   The operation.
	 * @param int    $item The item.
	 * @return mixed
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
	 * A classic menu with a parent item P and a child C under it, both published. Returns the menu, the parent and the child.
	 *
	 * @return array{menu: int, parent: int, child: int}
	 */
	private function menu_with_parent_and_child(): array {
		$menu   = $this->classic_menu( 'Nested ' . wp_generate_password( 6, false ) );
		$parent = $this->custom_item( $menu, 'Parent', 'https://example.com/parent' );
		$child  = $this->custom_item( $menu, 'Child', 'https://example.com/child', $parent );

		return array(
			'menu'   => $menu,
			'parent' => $parent,
			'child'  => $child,
		);
	}

	/**
	 * A parent with a visible child is refused for hide, and nothing changes. The child would show at the top level otherwise.
	 */
	public function test_hide_refuses_an_item_with_a_visible_child(): void {
		$fixture = $this->menu_with_parent_and_child();
		$this->menu_agent();

		$result = $this->call( $fixture['menu'], 'hide', $fixture['parent'] );

		$this->assert_refused( $result, 'steadhand_refused_has_children' );
		$this->assertSame( 'publish', get_post( $fixture['parent'] )->post_status );
		$this->assertSame( 0, $this->announced, 'A refused hide must not announce the menu.' );
	}

	/**
	 * An item marked as not yet saved to a menu (core's orphan mark) is refused for hide and for show.
	 */
	public function test_hide_refuses_an_item_marked_orphaned(): void {
		$menu = $this->classic_menu( 'Orphan ' . wp_generate_password( 6, false ) );
		$item = $this->draft_custom_item( $menu, 'Orphan', 'https://example.com/orphan' );
		update_post_meta( $item, MenuRules::ORPHANED_META, (string) time() );
		$this->menu_agent();

		$this->assert_refused( $this->call( $menu, 'hide', $item ), 'steadhand_refused_unsupported_item' );
		$this->assert_refused( $this->call( $menu, 'show', $item ), 'steadhand_refused_unsupported_item' );
		$this->assertSame( 'draft', get_post( $item )->post_status );
	}

	/**
	 * A hidden item is not removed by core's clean-up of orphaned drafts, which deletes only drafts that carry the orphan mark.
	 */
	public function test_a_hidden_item_is_not_deleted_by_cores_orphan_cleanup(): void {
		$fixture = $this->menu_with_parent_and_child();
		$this->menu_agent();
		$this->call( $fixture['menu'], 'hide', $fixture['child'] );

		if ( ! function_exists( '_wp_delete_orphaned_draft_menu_items' ) ) {
			require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
		}
		_wp_delete_orphaned_draft_menu_items();

		$this->assertInstanceOf( \WP_Post::class, get_post( $fixture['child'] ), 'The hidden item must survive core\'s clean-up.' );
		$this->assertSame( 'draft', get_post( $fixture['child'] )->post_status );
	}

	/**
	 * A draft that Steadhand did not hide (an administrator's, with none of the hidden keys) is refused for show.
	 */
	public function test_show_refuses_a_draft_item_steadhand_did_not_hide(): void {
		$menu  = $this->classic_menu( 'Admin ' . wp_generate_password( 6, false ) );
		$draft = $this->draft_custom_item( $menu, 'Admin draft', 'https://example.com/admin-draft' );
		$this->menu_agent();

		$result = $this->call( $menu, 'show', $draft );

		$this->assert_refused( $result, 'steadhand_refused_not_hidden_by_steadhand' );
		$this->assertSame( 'draft', get_post( $draft )->post_status, 'The administrator\'s draft must stay hidden.' );
	}

	/**
	 * An item whose parent is hidden is refused for show: it would show at the top level.
	 */
	public function test_show_refuses_when_the_parent_is_hidden(): void {
		$fixture = $this->menu_with_parent_and_child();
		$this->menu_agent();
		$this->assertIsArray( $this->call( $fixture['menu'], 'hide', $fixture['child'] ) );
		$this->assertIsArray( $this->call( $fixture['menu'], 'hide', $fixture['parent'] ) );

		$result = $this->call( $fixture['menu'], 'show', $fixture['child'] );

		$this->assert_refused( $result, 'steadhand_refused_hidden_parent' );
		$this->assertSame( 'draft', get_post( $fixture['child'] )->post_status );
	}

	/**
	 * A post link whose post is no longer published is refused for show.
	 */
	public function test_show_refuses_when_the_linked_post_is_no_longer_published(): void {
		$menu = $this->classic_menu( 'Linked ' . wp_generate_password( 6, false ) );
		$post = $this->admin_post( array( 'post_title' => 'Linked page' ) );
		$item = (int) wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'post',
				'menu-item-object-id' => $post,
				'menu-item-status'    => 'publish',
			)
		);
		$this->menu_agent();
		$this->assertIsArray( $this->call( $menu, 'hide', $item ) );

		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'draft',
			)
		);

		$this->assert_refused( $this->call( $menu, 'show', $item ), 'steadhand_refused_link_target' );
		$this->assertSame( 'draft', get_post( $item )->post_status );
	}

	/**
	 * An item that changed after the hide, by anyone, is refused for show: the modified time the hide left no longer matches.
	 */
	public function test_show_refuses_a_hidden_item_that_changed_after_the_hide(): void {
		$menu = $this->classic_menu( 'Changed ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Before the hide', 'https://example.com/before' );
		$this->menu_agent();
		$this->assertIsArray( $this->call( $menu, 'hide', $item ) );

		// An edit made later moves the modified time. post_modified_gmt has one-second resolution, so the test moves it an hour on
		// instead of waiting: a second edit in the same second is a limit the SPEC states, not what this test checks.
		global $wpdb;
		$later = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $later ), array( 'ID' => $item ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The test moves the modified time, as a later save would.
		clean_post_cache( $item );

		$this->assert_refused( $this->call( $menu, 'show', $item ), 'steadhand_refused_not_hidden_by_steadhand' );
		$this->assertSame( 'draft', get_post( $item )->post_status );
	}

	/**
	 * Hiding and showing keep an administrator's title markup byte for byte, through the ability.
	 */
	public function test_hide_and_show_keep_an_administrators_title_markup_byte_for_byte(): void {
		wp_set_current_user( $this->admin_id() );
		$menu   = $this->classic_menu( 'Markup ' . wp_generate_password( 6, false ) );
		$item   = $this->custom_item( $menu, '<i class="fa-home">Home</i>', 'https://example.com/home' );
		$stored = get_post( $item )->post_title;
		$this->assertStringContainsString( 'class="fa-home"', $stored, 'The fixture must hold the markup before the agent acts.' );
		$this->menu_agent();

		$this->assertIsArray( $this->call( $menu, 'hide', $item ) );
		$this->assertSame( $stored, get_post( $item )->post_title );
		$this->assertIsArray( $this->call( $menu, 'show', $item ) );
		$this->assertSame( $stored, get_post( $item )->post_title );
	}

	/**
	 * A hide and a show change no post meta but the hidden keys: every other key has the value it had.
	 */
	public function test_hide_and_show_change_no_meta_but_the_marker(): void {
		$menu   = $this->classic_menu( 'Meta ' . wp_generate_password( 6, false ) );
		$item   = $this->custom_item( $menu, 'Meta item', 'https://example.com/meta' );
		$before = $this->meta_without_hidden_keys( $item );
		$this->menu_agent();

		$this->assertIsArray( $this->call( $menu, 'hide', $item ) );
		$this->assertSame( $before, $this->meta_without_hidden_keys( $item ), 'A hide must change no other meta.' );

		$this->assertIsArray( $this->call( $menu, 'show', $item ) );
		$this->assertSame( $before, $this->meta_without_hidden_keys( $item ), 'A show must change no other meta.' );
		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $item, $key ), "Show must leave no $key." );
		}
	}

	/**
	 * Publishing a hidden item by any route, here an administrator's status change, removes the hidden keys. A later draft made by an
	 * administrator is then not one an agent can show.
	 */
	public function test_the_marker_is_removed_when_wp_admin_republishes_the_item(): void {
		$menu = $this->classic_menu( 'Republish ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Republished', 'https://example.com/republished' );
		$this->menu_agent();
		$this->assertIsArray( $this->call( $menu, 'hide', $item ) );
		$this->assertSame( 1, $this->rows_of( $item, MenuRules::HIDDEN_META ) );

		wp_update_post(
			array(
				'ID'          => $item,
				'post_status' => 'publish',
			)
		);
		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $item, $key ), "Publishing must remove $key." );
		}

		wp_update_post(
			array(
				'ID'          => $item,
				'post_status' => 'draft',
			)
		);
		$this->assert_refused( $this->call( $menu, 'show', $item ), 'steadhand_refused_not_hidden_by_steadhand' );
	}

	/**
	 * A hide of an item that is already a draft reports no change and writes nothing, and announces nothing.
	 */
	public function test_hide_twice_reports_changed_false_and_writes_nothing(): void {
		$menu = $this->classic_menu( 'Twice ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Twice', 'https://example.com/twice' );
		$this->menu_agent();
		$this->assertIsArray( $this->call( $menu, 'hide', $item ) );
		$announced = $this->announced;
		$modified  = get_post( $item )->post_modified_gmt;

		$output = $this->call( $menu, 'hide', $item );

		$this->assertIsArray( $output );
		$this->assertFalse( $output['changed'] );
		$this->assertSame( array(), $output['warnings'] );
		$this->assertSame( $announced, $this->announced );
		$this->assertSame( $modified, get_post( $item )->post_modified_gmt, 'A hide that changes nothing must not save the item.' );
	}

	/**
	 * A show of an item that is already published reports no change and writes nothing.
	 */
	public function test_show_of_a_visible_item_changes_nothing(): void {
		$menu = $this->classic_menu( 'Visible ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Visible', 'https://example.com/visible' );
		$this->menu_agent();

		$output = $this->call( $menu, 'show', $item );

		$this->assertIsArray( $output );
		$this->assertFalse( $output['changed'] );
		$this->assertSame( 0, $this->announced );
	}

	/**
	 * A hide puts the item on the draft status and the output says it is hidden, with the warning about Save Menu, and the state before.
	 */
	public function test_a_hide_reports_the_state_it_left_and_the_save_menu_warning(): void {
		$menu = $this->classic_menu( 'Report ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Report', 'https://example.com/report' );
		$this->menu_agent();

		$output = $this->call( $menu, 'hide', $item );

		$this->assertIsArray( $output );
		$this->assertTrue( $output['changed'] );
		$this->assertTrue( $output['item']['hidden'] );
		$this->assertFalse( $output['previous']['hidden'] );
		$this->assertSame( 'Report', $output['previous']['title'] );
		$this->assertNotEmpty( array_filter( $output['warnings'], static fn( string $warning ): bool => str_contains( $warning, 'Save Menu' ) ) );
	}

	/**
	 * A show puts a hidden item back on the public menu, and the output says so.
	 */
	public function test_a_show_publishes_the_item_again(): void {
		$menu = $this->classic_menu( 'Back ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Back', 'https://example.com/back' );
		$this->menu_agent();
		$this->assertIsArray( $this->call( $menu, 'hide', $item ) );

		$output = $this->call( $menu, 'show', $item );

		$this->assertIsArray( $output );
		$this->assertTrue( $output['changed'] );
		$this->assertFalse( $output['item']['hidden'] );
		$this->assertTrue( $output['previous']['hidden'] );
		$this->assertSame( 'publish', get_post( $item )->post_status );
	}

	/**
	 * The metadata of an item without the hidden keys, as a comparable array.
	 *
	 * @param int $item Item ID.
	 * @return array<string, mixed>
	 */
	private function meta_without_hidden_keys( int $item ): array {
		wp_cache_delete( $item, 'post_meta' );
		$all = get_post_meta( $item );

		return array_diff_key( $all, array_flip( MenuRules::HIDDEN_KEYS ) );
	}

	/**
	 * The keys uninstall.php removes are the hidden keys, and deleting them by key clears them from an item. uninstall.php itself is
	 * not run here: it drops the change-log table, which the rest of the suite needs.
	 */
	public function test_the_hidden_keys_uninstall_removes_are_the_ones_a_hide_stores(): void {
		$this->assertSame(
			array( '_steadhand_menu_hidden', '_steadhand_menu_hidden_item', '_steadhand_menu_hidden_modified' ),
			MenuRules::HIDDEN_KEYS
		);

		$menu = $this->classic_menu( 'Uninstall ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Uninstall', 'https://example.com/uninstall' );
		$this->menu_agent();
		$this->assertIsArray( $this->call( $menu, 'hide', $item ) );

		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			$this->assertSame( 1, $this->rows_of( $item, $key ), "A hide must store $key." );
		}

		array_map( 'delete_post_meta_by_key', MenuRules::HIDDEN_KEYS );

		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $item, $key ), "The uninstall path must remove $key." );
		}
	}
}
