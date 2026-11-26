<?php
/**
 * Tests for steadhand/update-menu's read-back and save checks: an item whose linked post is trashed is a success, every save of an
 * existing item keeps its text (a listener that alters it is reported as partial), and a change whose before state could not be saved
 * says so in its warnings.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Each test runs the ability through the pipeline as a dedicated agent with steadhand_menus and steadhand_publish.
 */
final class UpdateMenuGuardsTest extends MenuWriteTestCase {

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
	 * Counts permanent deletes, which tearDown() checks.
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
	}

	/**
	 * Checks that no permanent delete happened.
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
	 * The arguments of a call on an item.
	 *
	 * @param int                  $menu The menu.
	 * @param string               $op   The operation.
	 * @param int                  $item The item.
	 * @param array<string, mixed> $more Further fields.
	 * @return array<string, mixed>
	 */
	private function call_args( int $menu, string $op, int $item, array $more = array() ): array {
		return array_merge(
			array(
				'kind' => 'classic',
				'menu' => $menu,
				'op'   => $op,
				'item' => $item,
			),
			$more
		);
	}

	/**
	 * Makes a save_post listener that saves the item once more, with other text, inside the first save. The second save is not put back
	 * by the keep-text guard, so the item's text changes behind the call.
	 *
	 * @param int $item The item.
	 */
	private function resave_once_with_other_text( int $item ): void {
		$done = false;
		$this->on(
			'save_post',
			function ( int $post_id ) use ( &$done, $item ): void {
				if ( $post_id === $item && ! $done ) {
					$done = true;
					wp_update_post(
						array(
							'ID'           => $item,
							'post_content' => 'Changed by a listener',
						)
					);
				}
			},
			10,
			1
		);
	}

	/**
	 * A post link whose post is trashed (core marks the item invalid) still hides: the call succeeds, and the output says the item is hidden.
	 */
	public function test_hiding_an_item_whose_linked_post_is_trashed_reports_success(): void {
		wp_set_current_user( $this->admin_id() );
		$menu = $this->classic_menu( 'Trashed ' . wp_generate_password( 6, false ) );
		$post = $this->admin_post( array( 'post_title' => 'Trashed page' ) );
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
		wp_trash_post( $post );
		$this->menu_agent();

		$output = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $item ) );

		$this->assertIsArray( $output, 'A hide of an item with a trashed link is a success, not a partial failure.' );
		$this->assertTrue( $output['changed'] );
		$this->assertTrue( $output['item']['hidden'] );
		$this->assertSame( 'draft', $this->raw_row( $item )->post_status );
	}

	/**
	 * A save_post listener alters the item's text during a hide: the call reports partial failure, not success.
	 */
	public function test_a_hide_whose_text_a_listener_alters_is_reported_as_partial(): void {
		wp_set_current_user( $this->admin_id() );
		$menu = $this->classic_menu( 'Hide text ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Kept', 'https://example.com/kept' );
		wp_update_post(
			array(
				'ID'           => $item,
				'post_content' => '<i class="keep">kept</i>',
			)
		);
		$this->menu_agent();
		$this->resave_once_with_other_text( $item );

		$result = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $item ) );

		$this->assert_refused( $result, 'steadhand_update_partial' );
	}

	/**
	 * The same listener during a rename: the title is the one renamed, but the content was altered, so the call reports partial failure.
	 */
	public function test_a_rename_whose_text_a_listener_alters_is_reported_as_partial(): void {
		wp_set_current_user( $this->admin_id() );
		$menu = $this->classic_menu( 'Rename text ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Kept', 'https://example.com/kept' );
		wp_update_post(
			array(
				'ID'           => $item,
				'post_content' => '<i class="keep">kept</i>',
			)
		);
		$this->menu_agent();
		$this->resave_once_with_other_text( $item );

		$result = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'update', $item, array( 'title' => 'Renamed' ) ) );

		$this->assert_refused( $result, 'steadhand_update_partial' );
	}

	/**
	 * A hide whose text check fails has still hidden the item: the call reports partial failure, the item is a draft, and it keeps the
	 * hidden record, so a following show works.
	 */
	public function test_a_hide_whose_text_check_fails_stays_hidden_and_can_be_shown_again(): void {
		wp_set_current_user( $this->admin_id() );
		$menu = $this->classic_menu( 'Hide check ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Kept', 'https://example.com/kept' );
		wp_update_post(
			array(
				'ID'           => $item,
				'post_content' => '<i class="keep">kept</i>',
			)
		);
		$this->menu_agent();
		$this->resave_once_with_other_text( $item );

		$result = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $item ) );

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( 'IS hidden', $result->get_error_message() );
		$this->assertSame( 'draft', $this->raw_row( $item )->post_status, 'The status did change: the item is hidden.' );
		$this->assertSame( 1, $this->rows_of( $item, \Steadhand\Pipeline\MenuRules::HIDDEN_META ), 'The hidden record must be stored.' );

		$shown = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'show', $item ) );
		$this->assertIsArray( $shown, 'A following show must work.' );
		$this->assertSame( 'publish', $this->raw_row( $item )->post_status );
	}

	/**
	 * A show whose text check fails has made the item visible, and removes its hidden keys, so the call says the item is visible.
	 */
	public function test_a_show_whose_text_check_fails_leaves_the_item_visible_without_keys(): void {
		wp_set_current_user( $this->admin_id() );
		$menu = $this->classic_menu( 'Show check ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Kept', 'https://example.com/kept' );
		wp_update_post(
			array(
				'ID'           => $item,
				'post_content' => '<i class="keep">kept</i>',
			)
		);
		$this->menu_agent();
		$this->assertIsArray( $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $item ) ) );
		$this->resave_once_with_other_text( $item );

		$result = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'show', $item ) );

		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( 'visible', $result->get_error_message() );
		$this->assertSame( 'publish', $this->raw_row( $item )->post_status );
		foreach ( \Steadhand\Pipeline\MenuRules::HIDDEN_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $item, $key ), "A show whose text check failed must remove $key." );
		}
	}

	/**
	 * A hidden item whose record is stale (edited by other code after the hide) is not made showable by a rename: the rename does not
	 * refresh the modified key, and show stays refused.
	 */
	public function test_a_rename_of_a_hidden_item_with_a_stale_record_does_not_refresh_it(): void {
		$menu = $this->classic_menu( 'Stale ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Stale', 'https://example.com/stale' );
		$this->menu_agent();
		$this->assertIsArray( $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $item ) ) );

		// Other code edits the item after the hide. The modified time moves an hour on, as a later save would, so the record is stale.
		global $wpdb;
		$later = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $later ), array( 'ID' => $item ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The test moves the modified time, as a later save would.
		clean_post_cache( $item );
		// The modified time has a one-second resolution, so a rename made in the same second as the hide would match the record again.
		// The record is set to a value the item cannot have, so the record is stale whatever the clock says.
		update_post_meta( $item, \Steadhand\Pipeline\MenuRules::HIDDEN_MODIFIED_META, '2000-01-01 00:00:00' );
		$this->assertFalse( \Steadhand\Pipeline\MenuRules::hide_recorded( $item ), 'The fixture must have a stale record.' );
		$stored = '2000-01-01 00:00:00';

		$renamed = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'update', $item, array( 'title' => 'Renamed while stale' ) ) );
		$this->assertIsArray( $renamed );

		wp_cache_delete( $item, 'post_meta' );
		$this->assertSame( $stored, (string) get_post_meta( $item, \Steadhand\Pipeline\MenuRules::HIDDEN_MODIFIED_META, true ), 'A rename of a stale record must not refresh it.' );
		$this->assertFalse( \Steadhand\Pipeline\MenuRules::hide_recorded( $item ) );
		$this->assert_refused( $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'show', $item ) ), 'steadhand_refused_not_hidden_by_steadhand' );
		$this->assertSame( 'draft', $this->raw_row( $item )->post_status );
	}

	/**
	 * A change whose before state is too large to save (over 16 KB, so the snapshot store refuses it) is made, and the output says that the
	 * state could not be saved and the change cannot be undone. A change whose state is saved gets no such warning.
	 */
	public function test_a_change_whose_before_state_cannot_be_saved_says_so(): void {
		wp_set_current_user( $this->admin_id() );
		$menu  = $this->classic_menu( 'Unsaved ' . wp_generate_password( 6, false ) );
		$big   = $this->custom_item( $menu, str_repeat( 'x', 20000 ), 'https://example.com/big' );
		$small = $this->custom_item( $menu, 'Small', 'https://example.com/small' );
		$this->menu_agent();
		$warning = 'Steadhand could not keep a record of the state before this change, so there is no record of it.';

		$unsaved = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $big ) );
		$this->assertIsArray( $unsaved );
		$this->assertTrue( $unsaved['changed'] );
		$this->assertContains( $warning, $unsaved['warnings'], 'A change without its before state must say so.' );

		$saved = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $small ) );
		$this->assertIsArray( $saved );
		$this->assertNotContains( $warning, $saved['warnings'], 'A change whose state was saved gets no such warning.' );
	}
}
