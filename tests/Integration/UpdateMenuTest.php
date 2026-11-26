<?php
/**
 * Tests for steadhand/update-menu: the permission and object checks, the input rules that apply to every operation, the rule that no
 * item is ever deleted, the fixed parts of a menu, the change log and the registration. Most tests are negative.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Log\Entry;
use Steadhand\Pipeline\MenuRules;

/**
 * The shared fixtures are in MenuWriteTestCase. Each operation's own rules are in UpdateMenuAddTest and UpdateMenuHideShowTest.
 */
final class UpdateMenuTest extends MenuWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * The one generic text of an object refusal.
	 */
	private const NOT_FOUND = 'Not found, or this agent may not access it.';

	/**
	 * How many times wp_delete_post() ran (before_delete_post fires inside it).
	 *
	 * @var int
	 */
	private int $deletes = 0;

	/**
	 * Counts every permanent delete for tearDown(), which fails when one happened.
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
	 * No test may permanently delete a post, and every test must leave the counter at zero.
	 */
	protected function tearDown(): void {
		$this->assertSame( 0, $this->deletes, 'update-menu must never call wp_delete_post().' );
		parent::tearDown();
	}

	/**
	 * Makes the current user an agent that holds steadhand_menus and steadhand_publish, and returns its ID.
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
	 * A classic menu with two published custom links, A and B, and the menu's term ID. The fixtures are created with no user.
	 *
	 * @return array{menu: int, a: int, b: int}
	 */
	private function menu_with_two_items(): array {
		$menu = $this->classic_menu( 'Main ' . wp_generate_password( 6, false ) );

		return array(
			'menu' => $menu,
			'a'    => $this->custom_item( $menu, 'Alpha', 'https://example.com/alpha' ),
			'b'    => $this->custom_item( $menu, 'Beta', 'https://example.com/beta' ),
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
	 * The fields that describe a menu and its theme location, as a comparable array.
	 *
	 * @param int $menu The menu.
	 * @return array<string, mixed>
	 */
	private function menu_settings( int $menu ): array {
		$term = wp_get_nav_menu_object( $menu );

		return array(
			'name'      => $term instanceof \WP_Term ? $term->name : '',
			'slug'      => $term instanceof \WP_Term ? $term->slug : '',
			'locations' => get_nav_menu_locations(),
			'options'   => get_option( 'nav_menu_options' ),
			'theme_mod' => get_option( 'theme_mods_' . get_stylesheet() ),
		);
	}

	/**
	 * A call without steadhand_menus is refused by the pipeline, and nothing changes.
	 */
	public function test_refused_without_menus(): void {
		$fixture = $this->menu_with_two_items();
		$this->act_as_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['a'] ) ) );

		$this->assert_refused( $result, 'steadhand_forbidden' );
		$this->assertStringContainsString( 'steadhand_menus', $result->get_error_message() );
		$this->assertSame( 'publish', get_post( $fixture['a'] )->post_status );
	}

	/**
	 * A caller without steadhand_read gets the generic refusal, and nothing changes.
	 */
	public function test_refused_without_read(): void {
		$fixture = $this->menu_with_two_items();
		$this->menu_agent( array( Capabilities::READ => false ) );

		$result = $this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['a'] ) ) );

		$this->assert_generic( $result );
		$this->assertSame( 'publish', get_post( $fixture['a'] )->post_status );
	}

	/**
	 * A caller without steadhand_publish is refused with the text that names the capability it lacks. Nothing changes.
	 */
	public function test_refused_without_publish_with_the_named_text(): void {
		$fixture = $this->menu_with_two_items();
		$this->menu_agent( array( Capabilities::PUBLISH => false ) );

		$result = $this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['a'] ) ) );

		$this->assert_refused(
			$result,
			'steadhand_forbidden',
			'Changing a menu needs the steadhand_publish capability as well as steadhand_menus, because a menu change is live on the public site at once. This agent does not hold steadhand_publish. Nothing was changed.'
		);
		$this->assertSame( 'publish', get_post( $fixture['a'] )->post_status );
	}

	/**
	 * A block navigation request is refused as invalid input, and the refusal is logged as blocked.
	 */
	public function test_block_kind_is_refused_and_logged(): void {
		$fixture = $this->menu_with_two_items();
		$nav     = $this->block_navigation( 'publish' );
		$agent   = $this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'kind' => 'block',
				'menu' => $nav,
				'op'   => 'hide',
				'item' => $fixture['a'],
			)
		);

		$this->assert_refused(
			$result,
			'steadhand_invalid_input',
			'Block navigation cannot be changed with this tool; only classic menus.'
		);
		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_BLOCKED, $row['result'] );
		$this->assertSame( self::ABILITY, $row['ability'] );
	}

	/**
	 * A wp_navigation post's ID, given as the menu, is refused with the generic text, not with a message that tells the two apart.
	 */
	public function test_a_wp_navigation_post_id_as_menu_is_refused_with_the_generic_text(): void {
		$fixture = $this->menu_with_two_items();
		$nav     = $this->block_navigation( 'publish' );
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( $nav, 'hide', array( 'item' => $fixture['a'] ) ) );

		$this->assert_generic( $result );
		$this->assertSame( 'publish', get_post( $fixture['a'] )->post_status );
	}

	/**
	 * A missing menu and a category ID as the menu get the same refusal.
	 */
	public function test_missing_menu_and_a_category_id_get_the_same_refusal(): void {
		$fixture  = $this->menu_with_two_items();
		$category = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->menu_agent();

		$missing = $this->execute_ability( self::ABILITY, $this->args( self::MISSING_ID, 'hide', array( 'item' => $fixture['a'] ) ) );
		$wrong   = $this->execute_ability( self::ABILITY, $this->args( $category, 'hide', array( 'item' => $fixture['a'] ) ) );

		$this->assert_generic( $missing );
		$this->assert_generic( $wrong );
		$this->assertSame( 'publish', get_post( $fixture['a'] )->post_status );
	}

	/**
	 * An extra property, which the schema does not name, is refused as invalid input.
	 */
	public function test_extra_property_is_refused(): void {
		$fixture = $this->menu_with_two_items();
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'hide',
				array(
					'item'      => $fixture['a'],
					'css_class' => 'x',
				)
			)
		);

		$this->assert_refused( $result, 'steadhand_invalid_input' );
		$this->assertSame( 'publish', get_post( $fixture['a'] )->post_status );
	}

	/**
	 * Each operation refuses the fields it does not take, and nothing changes.
	 */
	public function test_each_op_refuses_fields_it_does_not_take(): void {
		$fixture = $this->menu_with_two_items();
		$this->menu_agent();

		$refused = array(
			array(
				'hide',
				array(
					'item'  => $fixture['a'],
					'title' => 'Renamed',
				),
			),
			array(
				'hide',
				array(
					'item'   => $fixture['a'],
					'parent' => $fixture['b'],
				),
			),
			array(
				'show',
				array(
					'item' => $fixture['a'],
					'url'  => 'https://example.com/new',
				),
			),
			array(
				'update',
				array(
					'item'   => $fixture['a'],
					'title'  => 'Renamed',
					'parent' => $fixture['b'],
				),
			),
			array(
				'update',
				array(
					'item'    => $fixture['a'],
					'title'   => 'Renamed',
					'post_id' => 1,
				),
			),
			array(
				'add',
				array(
					'type'  => 'custom',
					'url'   => 'https://example.com/new',
					'title' => 'New',
					'item'  => $fixture['a'],
				),
			),
		);

		foreach ( $refused as [ $op, $more ] ) {
			$result = $this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], $op, $more ) );
			$this->assert_refused( $result, 'steadhand_invalid_input', null );
		}

		$this->assertSame( 'Alpha', get_post( $fixture['a'] )->post_title );
		$this->assertSame( 'publish', get_post( $fixture['a'] )->post_status );
	}

	/**
	 * An item of another menu is refused with the generic text, whatever the operation.
	 */
	public function test_an_item_of_another_menu_is_refused(): void {
		$fixture = $this->menu_with_two_items();
		$other   = $this->classic_menu( 'Footer ' . wp_generate_password( 6, false ) );
		$foreign = $this->custom_item( $other, 'Foreign', 'https://example.com/foreign' );
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $foreign ) ) );

		$this->assert_generic( $result );
		$this->assertSame( 'publish', get_post( $foreign )->post_status );
	}

	/**
	 * A post's ID given as an item is refused with the generic text, and the post is not changed.
	 */
	public function test_a_post_id_as_item_is_refused(): void {
		$fixture = $this->menu_with_two_items();
		$post    = $this->admin_post( array( 'post_title' => 'Not an item' ) );
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'update',
				array(
					'item'  => $post,
					'title' => 'Renamed',
				)
			)
		);

		$this->assert_generic( $result );
		$this->assertSame( 'Not an item', get_post( $post )->post_title );
	}

	/**
	 * A trashed item is refused with the generic text, for every operation that names one.
	 */
	public function test_a_trashed_item_is_refused(): void {
		$fixture = $this->menu_with_two_items();
		wp_update_post(
			array(
				'ID'          => $fixture['a'],
				'post_status' => 'trash',
			)
		);
		$this->menu_agent();

		foreach ( array( 'update', 'hide', 'show' ) as $op ) {
			$more   = 'update' === $op ? array(
				'item'  => $fixture['a'],
				'title' => 'Renamed',
			) : array( 'item' => $fixture['a'] );
			$result = $this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], $op, $more ) );
			$this->assert_generic( $result );
		}

		$this->assertSame( 'trash', get_post( $fixture['a'] )->post_status, 'A trashed item must be left in the trash.' );
	}

	/**
	 * No operation deletes an item, a menu or any other post, including the refused calls. The tearDown() counter checks it for the
	 * whole test; this test runs every operation that can succeed, and the refusals that follow them.
	 */
	public function test_no_menu_item_is_ever_deleted(): void {
		$fixture = $this->menu_with_two_items();
		$this->menu_agent();
		$before = $this->item_ids( $fixture['menu'] );

		$calls = array(
			$this->args(
				$fixture['menu'],
				'add',
				array(
					'type'  => 'custom',
					'url'   => 'https://example.com/new',
					'title' => 'New',
				)
			),
			$this->args(
				$fixture['menu'],
				'update',
				array(
					'item'  => $fixture['a'],
					'title' => 'Alpha 2',
				)
			),
			$this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['b'] ) ),
			$this->args( $fixture['menu'], 'show', array( 'item' => $fixture['b'] ) ),
			$this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['b'] ) ),
		);
		foreach ( $calls as $call ) {
			$this->execute_ability( self::ABILITY, $call );
		}

		$this->assertSame( 0, $this->deletes );
		$this->assertSame( array(), array_diff( $before, $this->item_ids( $fixture['menu'] ) ), 'No item of the menu may disappear.' );
	}

	/**
	 * The menu's name, slug, theme locations, the nav_menu_options option and the theme mods are the same after every operation.
	 */
	public function test_the_menu_name_slug_locations_and_auto_add_option_are_unchanged_by_every_op(): void {
		$fixture = $this->menu_with_two_items();
		$this->assign_location( $fixture['menu'] );
		$before = $this->menu_settings( $fixture['menu'] );
		$this->menu_agent();

		$this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'add',
				array(
					'type'  => 'custom',
					'url'   => 'https://example.com/n',
					'title' => 'N',
				)
			)
		);
		$this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'update',
				array(
					'item'  => $fixture['a'],
					'title' => 'Alpha 3',
				)
			)
		);
		$this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['b'] ) ) );
		$this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'show', array( 'item' => $fixture['b'] ) ) );

		$this->assertSame( $before, $this->menu_settings( $fixture['menu'] ) );
	}

	/**
	 * A refused call writes no row, no post meta, and no term relationship: the counts are the same before and after.
	 */
	public function test_a_refusal_writes_no_row_no_meta_and_no_term_relationship(): void {
		global $wpdb;

		$fixture = $this->menu_with_two_items();
		$this->menu_agent( array( Capabilities::PUBLISH => false ) );

		$counts = static fn(): array => array(
			'posts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fixed count of a core table.
			'meta'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fixed count of a core table.
			'relations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fixed count of a core table.
		);
		$before = $counts();

		$result = $this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'add',
				array(
					'type'  => 'custom',
					'url'   => 'https://example.com/n',
					'title' => 'N',
				)
			)
		);

		$this->assert_refused( $result, 'steadhand_forbidden' );
		$this->assertSame( $before, $counts(), 'A refused call must write nothing.' );
	}

	/**
	 * A call does not write theme mods. The theme's option is the same before and after a successful operation.
	 */
	public function test_theme_mods_are_not_written(): void {
		$fixture = $this->menu_with_two_items();
		$this->assign_location( $fixture['menu'] );
		$before = get_option( 'theme_mods_' . get_stylesheet() );
		$this->menu_agent();

		$this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['a'] ) ) );

		$this->assertSame( $before, get_option( 'theme_mods_' . get_stylesheet() ) );
	}

	/**
	 * A successful hide is logged with the item's type and ID, the snapshot ID above zero, and not undoable.
	 */
	public function test_a_change_is_logged_against_the_item_with_a_snapshot_and_not_undoable(): void {
		$fixture = $this->menu_with_two_items();
		$agent   = $this->menu_agent();

		$this->execute_ability( self::ABILITY, $this->args( $fixture['menu'], 'hide', array( 'item' => $fixture['a'] ) ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( self::ABILITY, $row['ability'] );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 'nav_menu_item', $row['object_type'] );
		$this->assertSame( $fixture['a'], (int) $row['object_id'] );
		$this->assertGreaterThan( 0, (int) $row['snapshot_id'], 'The before state is saved.' );
		$this->assertSame( 0, (int) $row['undoable'], 'The call is not undoable yet.' );
	}

	/**
	 * A logged add names the new item, not zero, once the item exists.
	 */
	public function test_a_logged_add_names_the_new_item(): void {
		$fixture = $this->menu_with_two_items();
		$agent   = $this->menu_agent();

		$output = $this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'add',
				array(
					'type'  => 'custom',
					'url'   => 'https://example.com/new',
					'title' => 'New',
				)
			)
		);

		$this->assertIsArray( $output );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'nav_menu_item', $row['object_type'] );
		$this->assertSame( (int) $output['item']['id'], (int) $row['object_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	/**
	 * Undo-change refuses an update-menu entry, so the change is undone only by its inverse call.
	 */
	public function test_undo_change_refuses_an_update_menu_entry(): void {
		$fixture = $this->menu_with_two_items();
		$agent   = $this->menu_agent();
		$this->execute_ability(
			self::ABILITY,
			$this->args(
				$fixture['menu'],
				'update',
				array(
					'item'  => $fixture['a'],
					'title' => 'Alpha 4',
				)
			)
		);
		$entry = (int) $this->latest_row( $agent )['id'];

		$result = $this->execute_ability( 'steadhand/undo-change', array( 'id' => $entry ) );

		$this->assertWPError( $result, 'undo-change must refuse an update-menu entry.' );
		$this->assertSame( 'Alpha 4', get_post( $fixture['a'] )->post_title, 'The change must stay.' );
	}

	/**
	 * Each call is counted as a write, so the rate limit applies to it.
	 */
	public function test_the_rate_limit_counts_the_ability_as_a_write(): void {
		$names = ( new \Steadhand\Plugin() )->registry()->write_names();

		$this->assertContains( self::ABILITY, $names );
	}

	/**
	 * The ability is registered as an ability of the Steadhand server only: not public, not in REST, and with its annotations.
	 */
	public function test_is_registered_with_strict_schemas_annotations_and_is_not_public(): void {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability );

		$input = $ability->get_input_schema();
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame( array( 'kind', 'menu', 'op' ), $input['required'] );
		$this->assertSame( array( 'classic', 'block' ), $input['properties']['kind']['enum'] );
		$this->assertSame( array( 'add', 'update', 'hide', 'show' ), $input['properties']['op']['enum'] );
		$this->assertArrayNotHasKey( 'after', $input['properties'], 'Step 2 has no move, so no after field.' );

		$output = $ability->get_output_schema();
		$this->assertFalse( $output['additionalProperties'] );
		$this->assertSame(
			array( 'kind', 'menu', 'op', 'item', 'previous', 'changed', 'warnings', 'guardrail_notes' ),
			$output['required']
		);

		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['idempotent'] );
		$this->assertFalse( $meta['mcp']['public'] );
		// WordPress 7.1 defaults meta.public to false. WordPress 6.9 has no such key at all.
		$this->assertFalse( (bool) ( $meta['public'] ?? false ), 'The ability must not be marked public.' );
	}

	/**
	 * Registration gives the ability the Steadhand registration arguments: no meta.public at any level.
	 */
	public function test_the_registration_arguments_do_not_mark_the_ability_public(): void {
		$ability = null;
		foreach ( ( new \Steadhand\Plugin() )->registry()->abilities() as $candidate ) {
			if ( self::ABILITY === $candidate->name() ) {
				$ability = $candidate;
			}
		}
		$this->assertNotNull( $ability );

		$args = ( new \Steadhand\Plugin() )->registry()->args_for( $ability );
		$this->assertArrayNotHasKey( 'public', $args['meta'] );
		$this->assertFalse( $args['meta']['mcp']['public'] );
	}

	/**
	 * The item IDs of a menu, from the rows that include drafts.
	 *
	 * @param int $menu The menu.
	 * @return int[]
	 */
	private function item_ids( int $menu ): array {
		return array_map(
			static fn( array $row ): int => $row['id'],
			MenuRules::items_raw( $this->menu_term( $menu ) )
		);
	}
}
