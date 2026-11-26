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
	 * A change whose before state is too large to save (over 16 KB, so the snapshot store refuses it) is made, and the output says that the
	 * state could not be saved and the change cannot be undone. A change whose state is saved gets no such warning.
	 */
	public function test_a_change_whose_before_state_cannot_be_saved_says_so(): void {
		wp_set_current_user( $this->admin_id() );
		$menu  = $this->classic_menu( 'Unsaved ' . wp_generate_password( 6, false ) );
		$big   = $this->custom_item( $menu, str_repeat( 'x', 20000 ), 'https://example.com/big' );
		$small = $this->custom_item( $menu, 'Small', 'https://example.com/small' );
		$this->menu_agent();
		$warning = 'Steadhand could not save the state before this change, so it cannot be undone.';

		$unsaved = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $big ) );
		$this->assertIsArray( $unsaved );
		$this->assertTrue( $unsaved['changed'] );
		$this->assertContains( $warning, $unsaved['warnings'], 'A change without its before state must say so.' );

		$saved = $this->execute_ability( self::ABILITY, $this->call_args( $menu, 'hide', $small ) );
		$this->assertIsArray( $saved );
		$this->assertNotContains( $warning, $saved['warnings'], 'A change whose state was saved gets no such warning.' );
	}
}
