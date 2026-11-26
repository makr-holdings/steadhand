<?php
/**
 * Tests for an add that fails after its item exists (steadhand_update_partial), and for the tree rule when the menu changes during an
 * add, a show or a hide. Each failure is forced at the point core or the writer does the step, so the test does not depend on luck.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Log\Entry;
use Steadhand\Pipeline\MenuRules;
use Steadhand\Pipeline\MenuWriter;

/**
 * Each test runs the ability through the pipeline as a dedicated agent with steadhand_menus and steadhand_publish.
 */
final class UpdateMenuAddFailureTest extends MenuWriteTestCase {

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
	 * The nav_menu taxonomy object, while the test has taken it away, so that it can be put back.
	 *
	 * @var \WP_Taxonomy|null
	 */
	private ?\WP_Taxonomy $taxonomy = null;

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
	 * The arguments of an add of a custom link.
	 *
	 * @param int                  $menu The menu.
	 * @param array<string, mixed> $more Further fields.
	 * @return array<string, mixed>
	 */
	private function custom_add( int $menu, array $more = array() ): array {
		return array_merge(
			array(
				'kind'  => 'classic',
				'menu'  => $menu,
				'op'    => 'add',
				'type'  => 'custom',
				'url'   => 'https://example.com/new',
				'title' => 'New link',
			),
			$more
		);
	}

	/**
	 * The IDs of the items WordPress shows on the public menu.
	 *
	 * @param int $menu The menu.
	 * @return int[]
	 */
	private function public_ids( int $menu ): array {
		$items = wp_get_nav_menu_items( $menu );

		return is_array( $items ) ? array_map( 'intval', wp_list_pluck( $items, 'ID' ) ) : array();
	}

	/**
	 * Asserts that no published item of the menu has a parent that is not a published item of the menu.
	 *
	 * @param int $menu The menu.
	 */
	private function assert_no_public_item_under_a_hidden_parent( int $menu ): void {
		foreach ( MenuRules::items_raw( $this->menu_term( $menu ) ) as $row ) {
			if ( 'publish' !== $row['status'] || 0 === $row['parent'] ) {
				continue;
			}

			$parent = MenuRules::fresh_state( $row['parent'] );
			$this->assertNotNull( $parent, "Item {$row['id']} has a parent that is not in the menu." );
			$this->assertSame( 'publish', $parent['status'], "Item {$row['id']} is published under a hidden parent." );
		}
	}

	/**
	 * Takes the nav_menu taxonomy away when core has inserted an item, so that core's term assignment fails after the insert. The
	 * taxonomy is put back by restore_nav_menu_taxonomy(), which every test calls in a finally.
	 */
	private function take_nav_menu_taxonomy_away_on_insert(): void {
		$this->on(
			'wp_add_nav_menu_item',
			function (): void {
				global $wp_taxonomies;
				$this->taxonomy = $wp_taxonomies['nav_menu'];
				unset( $wp_taxonomies['nav_menu'] );
			},
			10,
			2
		);
	}

	/**
	 * Puts back the nav_menu taxonomy that take_nav_menu_taxonomy_away_on_insert() removed, if it did.
	 */
	private function restore_nav_menu_taxonomy(): void {
		if ( null !== $this->taxonomy ) {
			global $wp_taxonomies;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Puts back the object the test removed, exactly as it was.
			$wp_taxonomies['nav_menu'] = $this->taxonomy;
			$this->taxonomy            = null;
		}
	}

	/**
	 * When core cannot assign the new item to the menu after it inserted it, the call fails and names the item in its data and its
	 * message. The item is an unfinished draft in no menu: it gets core's orphan mark, the message says WordPress will remove it, nothing
	 * is public, and the change log names the item, not 0.
	 */
	public function test_a_term_failure_after_the_insert_names_the_item_marks_it_unfinished_and_logs_it(): void {
		$menu = $this->classic_menu( 'Term ' . wp_generate_password( 6, false ) );
		$this->custom_item( $menu, 'Existing', 'https://example.com/existing' );
		$agent = $this->menu_agent();

		$this->take_nav_menu_taxonomy_away_on_insert();
		try {
			$result = $this->execute_ability( self::ABILITY, $this->custom_add( $menu ) );
		} finally {
			$this->restore_nav_menu_taxonomy();
		}

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_update_partial', $result->get_error_code() );
		$item = (int) ( $result->get_error_data()['item'] ?? 0 );
		$this->assertGreaterThan( 0, $item, 'The error data must name the created item.' );
		$this->assertStringContainsString( (string) $item, $result->get_error_message(), 'The message must name the created item.' );
		$this->assertStringContainsString( 'WordPress will remove', $result->get_error_message() );

		$this->assertSame( 'draft', $this->raw_row( $item )->post_status );
		$this->assertNull( MenuRules::menu_of( $this->raw_row( $item ) ), 'The item must be in no menu.' );
		$this->assertNotSame( '', (string) get_post_meta( $item, MenuRules::ORPHANED_META, true ), 'The unfinished item must carry core\'s orphan mark.' );
		$this->assertNotContains( $item, $this->public_ids( $menu ), 'The unfinished item must not be public.' );

		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_ERROR, $row['result'] );
		$this->assertSame( 'nav_menu_item', $row['object_type'] );
		$this->assertSame( $item, (int) $row['object_id'], 'The change log must name the created item, not 0.' );
	}

	/**
	 * When the publish step fails, the item is a hidden draft in the menu. The message says so, and does not say it is live.
	 */
	public function test_a_failed_publish_step_leaves_the_item_as_a_hidden_draft_in_the_menu(): void {
		$menu = $this->classic_menu( 'Publish ' . wp_generate_password( 6, false ) );
		$this->custom_item( $menu, 'Existing', 'https://example.com/existing' );
		$agent = $this->menu_agent();

		// The publish step is refused: the save of an existing item to publish stores draft instead.
		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ): array {
				if ( ! empty( $postarr['ID'] ) && 'nav_menu_item' === ( $data['post_type'] ?? '' ) && 'publish' === ( $data['post_status'] ?? '' ) ) {
					$data['post_status'] = 'draft';
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $menu ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_update_partial', $result->get_error_code() );
		$item = (int) ( $result->get_error_data()['item'] ?? 0 );
		$this->assertGreaterThan( 0, $item );
		$this->assertStringContainsString( (string) $item, $result->get_error_message() );
		$this->assertStringContainsString( 'hidden draft', $result->get_error_message() );
		$this->assertStringNotContainsString( 'live on the public site', $result->get_error_message() );

		$this->assertSame( 'draft', $this->raw_row( $item )->post_status );
		$this->assertSame( $menu, (int) MenuRules::menu_of( $this->raw_row( $item ) )->term_id, 'The hidden draft stays in the menu.' );
		$this->assertSame( '', (string) get_post_meta( $item, MenuRules::ORPHANED_META, true ), 'An item in a menu is never marked as unfinished.' );
		$this->assertNotContains( $item, $this->public_ids( $menu ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( $item, (int) $row['object_id'] );
	}

	/**
	 * When the item is published after all, though the call expected a draft, the message says it is live and asks for a check.
	 */
	public function test_a_forced_publish_is_reported_as_live_and_not_as_a_hidden_draft(): void {
		$menu = $this->classic_menu( 'Forced ' . wp_generate_password( 6, false ) );
		$this->custom_item( $menu, 'Existing', 'https://example.com/existing' );
		$agent = $this->menu_agent();

		// A filter publishes the new item at the insert, so the writer finds it is not the draft it asked for.
		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ): array {
				if ( empty( $postarr['ID'] ) && 'nav_menu_item' === ( $data['post_type'] ?? '' ) && 'draft' === ( $data['post_status'] ?? '' ) ) {
					$data['post_status'] = 'publish';
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $menu ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_update_partial', $result->get_error_code() );
		$item = (int) ( $result->get_error_data()['item'] ?? 0 );
		$this->assertGreaterThan( 0, $item );
		$this->assertStringContainsString( 'live on the public site', $result->get_error_message() );
		$this->assertStringContainsString( 'steadhand/get-menu', $result->get_error_message() );
		$this->assertStringNotContainsString( 'hidden draft', $result->get_error_message() );

		$this->assertSame( 'publish', $this->raw_row( $item )->post_status );
		$this->assertContains( $item, $this->public_ids( $menu ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( $item, (int) $row['object_id'] );
	}

	/**
	 * The parent is hidden by another request after the writer checked it and before the insert finishes. The writer publishes the new
	 * item anyway, the tree check after the publish sees the hidden parent, and it puts the new item back to hidden.
	 */
	public function test_a_parent_hidden_while_the_item_is_added_is_put_back_and_the_new_item_stays_hidden(): void {
		$menu   = $this->classic_menu( 'Race add ' . wp_generate_password( 6, false ) );
		$parent = $this->custom_item( $menu, 'Parent', 'https://example.com/parent' );
		$agent  = $this->menu_agent();

		$hidden = false;
		$this->on(
			'wp_insert_post_data',
			function ( array $data, array $postarr ) use ( &$hidden, $parent ): array {
				if ( ! $hidden && empty( $postarr['ID'] ) && 'nav_menu_item' === ( $data['post_type'] ?? '' ) ) {
					$hidden = true;
					wp_update_post(
						array(
							'ID'          => $parent,
							'post_status' => 'draft',
						)
					);
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $menu, array( 'parent' => $parent ) ) );

		$this->assertTrue( $hidden, 'The race must have happened.' );
		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_update_partial', $result->get_error_code() );
		$child = (int) ( $result->get_error_data()['item'] ?? 0 );
		$this->assertGreaterThan( 0, $child );
		$this->assertStringContainsString( 'back to hidden', $result->get_error_message() );

		$this->assertSame( 'draft', $this->raw_row( $child )->post_status, 'The new item must be a hidden draft again.' );
		$this->assertSame( 'draft', $this->raw_row( $parent )->post_status );
		$this->assertSame( 1, $this->rows_of( $child, MenuRules::HIDDEN_META ), 'The put-back must leave the hidden key, so show can recognise the item.' );
		$this->assert_no_public_item_under_a_hidden_parent( $menu );

		$row = $this->latest_row( $agent );
		$this->assertSame( $child, (int) $row['object_id'] );
	}

	/**
	 * The parent is hidden by another request between the show's check and the show's save. The item is shown, the tree check sees the
	 * hidden parent, and the item is hidden again.
	 */
	public function test_a_parent_hidden_while_the_item_is_shown_is_put_back_to_hidden(): void {
		$menu   = $this->classic_menu( 'Race show ' . wp_generate_password( 6, false ) );
		$parent = $this->custom_item( $menu, 'Parent', 'https://example.com/parent' );
		$child  = $this->custom_item( $menu, 'Child', 'https://example.com/child', $parent );
		$this->menu_agent();
		$this->assertIsArray( $this->execute_ability( self::ABILITY, $this->args_for( $menu, 'hide', $child ) ) );

		$shown = false;
		$this->on(
			'wp_insert_post_data',
			function ( array $data, array $postarr ) use ( &$shown, $child, $parent ): array {
				if ( ! $shown && (int) ( $postarr['ID'] ?? 0 ) === $child && 'publish' === ( $data['post_status'] ?? '' ) ) {
					$shown = true;
					wp_update_post(
						array(
							'ID'          => $parent,
							'post_status' => 'draft',
						)
					);
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->execute_ability( self::ABILITY, $this->args_for( $menu, 'show', $child ) );

		$this->assertTrue( $shown, 'The race must have happened.' );
		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( 'hid it again', $result->get_error_message() );
		$this->assertSame( 'draft', $this->raw_row( $child )->post_status );
		$this->assertSame( 'draft', $this->raw_row( $parent )->post_status );
		$this->assertTrue( MenuRules::hide_recorded( $child ), 'The put-back must restore the keys a hide leaves.' );
		$this->assert_no_public_item_under_a_hidden_parent( $menu );
	}

	/**
	 * A visible child appears between the hide's check and the hide's save. The item is hidden, the tree check sees the visible child,
	 * and the item is shown again.
	 */
	public function test_a_visible_child_appearing_while_the_item_is_hidden_puts_the_item_back_to_visible(): void {
		$menu   = $this->classic_menu( 'Race hide ' . wp_generate_password( 6, false ) );
		$parent = $this->custom_item( $menu, 'Parent', 'https://example.com/parent' );
		$child  = $this->custom_item( $menu, 'Child', 'https://example.com/child', $parent );
		$this->menu_agent();
		$this->assertIsArray( $this->execute_ability( self::ABILITY, $this->args_for( $menu, 'hide', $child ) ) );

		$shown = false;
		$this->on(
			'wp_insert_post_data',
			function ( array $data, array $postarr ) use ( &$shown, $child, $parent ): array {
				if ( ! $shown && (int) ( $postarr['ID'] ?? 0 ) === $parent && 'draft' === ( $data['post_status'] ?? '' ) ) {
					$shown = true;
					wp_update_post(
						array(
							'ID'          => $child,
							'post_status' => 'publish',
						)
					);
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->execute_ability( self::ABILITY, $this->args_for( $menu, 'hide', $parent ) );

		$this->assertTrue( $shown, 'The race must have happened.' );
		$this->assert_refused( $result, 'steadhand_update_partial' );
		$this->assertStringContainsString( 'showed it again', $result->get_error_message() );
		$this->assertSame( 'publish', $this->raw_row( $parent )->post_status, 'The hide must be undone.' );
		$this->assertSame( 0, $this->rows_of( $parent, MenuRules::HIDDEN_META ), 'An undone hide leaves no hidden key.' );
		$this->assert_no_public_item_under_a_hidden_parent( $menu );
	}

	/**
	 * The writer compares the parent with the state the ability read, status included. A parent hidden after that read is refused with
	 * steadhand_refused_content_changed before the insert, and no item is created.
	 */
	public function test_the_writer_refuses_an_add_whose_parent_was_hidden_after_the_read(): void {
		$menu   = $this->classic_menu( 'Writer add ' . wp_generate_password( 6, false ) );
		$parent = $this->custom_item( $menu, 'Parent', 'https://example.com/parent' );
		$term   = $this->menu_term( $menu );
		$rows   = MenuRules::items_raw( $term );
		$max    = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row['order'] );
		}
		$read = MenuRules::fresh_state( $parent );
		$this->assertIsArray( $read );

		// The ability read the parent as published, then another request hid it.
		wp_update_post(
			array(
				'ID'          => $parent,
				'post_status' => 'draft',
			)
		);
		$this->act_as_agent();

		$result = $this->in_write(
			fn(): mixed => MenuWriter::add(
				$term,
				array(
					'type'      => 'custom',
					'object'    => 'custom',
					'object_id' => 0,
					'url'       => 'https://example.com/late',
					'title'     => 'Late',
					'parent'    => $parent,
					'position'  => $max + 1,
				),
				array(
					'count'        => count( $rows ),
					'max_order'    => $max,
					'parent_state' => array(
						'menu'   => (int) $term->term_id,
						'status' => 'publish',
						'parent' => 0,
						'order'  => $read['order'],
					),
				),
				static fn(): bool => true
			)
		);

		$this->assert_refused( $result, 'steadhand_refused_content_changed' );
		$this->assertSame( count( $rows ), count( MenuRules::items_raw( $term ) ), 'A refused add must insert nothing.' );
	}

	/**
	 * A parent that carries core's orphan mark cannot take a child: the add is refused as unsupported, and nothing is created.
	 */
	public function test_an_add_refuses_a_parent_marked_as_unfinished(): void {
		$menu   = $this->classic_menu( 'Orphan parent ' . wp_generate_password( 6, false ) );
		$parent = $this->draft_custom_item( $menu, 'Unfinished', 'https://example.com/unfinished' );
		update_post_meta( $parent, MenuRules::ORPHANED_META, (string) time() );
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'   => 'classic',
				'menu'   => $menu,
				'op'     => 'add',
				'type'   => 'custom',
				'url'    => 'https://example.com/child',
				'title'  => 'Child',
				'parent' => $parent,
			)
		);

		$this->assert_refused( $result, 'steadhand_refused_unsupported_item' );
		$this->assertSame( 1, count( MenuRules::items_raw( $this->menu_term( $menu ) ) ), 'A refused add must create no item.' );
	}

	/**
	 * The arguments of an operation on an existing item.
	 *
	 * @param int    $menu The menu.
	 * @param string $op   The operation.
	 * @param int    $item The item.
	 * @return array<string, mixed>
	 */
	private function args_for( int $menu, string $op, int $item ): array {
		return array(
			'kind' => 'classic',
			'menu' => $menu,
			'op'   => $op,
			'item' => $item,
		);
	}
}
