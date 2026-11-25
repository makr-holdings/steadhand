<?php
/**
 * The classic menu branches of MetaCaps (SPEC §5, the update-menu ability): assign_term on a menu and edit_post on a menu item,
 * granted only inside a write call of steadhand/update-menu. Most tests are negative; the positive control is the last one.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Every answer is asked from inside a real pipeline call, through NamedProbe, so the answer is the one a real call gets. A
 * question asked outside any call is asked with current_user_can() directly.
 */
final class MenuMetaCapsTest extends MenuToolTestCase {

	private const UPDATE = 'steadhand/update-menu';

	/**
	 * Filters this file adds to map_meta_cap, removed after each test.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $filters = array();

	/**
	 * Removes the filters this file adds.
	 */
	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->filters = array();
		parent::tearDown();
	}

	/**
	 * Adds a map_meta_cap filter and remembers it for tearDown().
	 *
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function map_filter( callable $callback, int $priority ): void {
		add_filter( 'map_meta_cap', $callback, $priority, 4 );
		$this->filters[] = array( 'map_meta_cap', $callback, $priority );
	}

	/**
	 * Runs $check inside a real call of the named ability and returns the check's answer.
	 *
	 * @param string  $ability Ability name the probe runs as.
	 * @param Closure $check   Check to run inside the call.
	 * @param bool    $write   Whether the call writes.
	 * @return mixed
	 */
	private function inside( string $ability, Closure $check, bool $write = true ): mixed {
		$result = ( new Pipeline( new SpyLogger() ) )->run( new NamedProbe( $ability, $check, $write ), array() );
		$this->assertIsArray( $result, 'The probe call must be allowed to run.' );

		return $result['answer'];
	}

	/**
	 * The answers that matter for a menu and one of its items, asked from inside a call of the given ability.
	 *
	 * @param string $ability Ability name.
	 * @param int    $item    Menu item post ID.
	 * @param int    $menu    Menu term ID.
	 * @param bool   $write   Whether the call writes.
	 * @return array<string, bool>
	 */
	private function answers_inside( string $ability, int $item, int $menu, bool $write = true ): array {
		return $this->inside(
			$ability,
			static fn(): array => array(
				'edit_item'          => current_user_can( 'edit_post', $item ),
				'read_item'          => current_user_can( 'read_post', $item ),
				'publish_item'       => current_user_can( 'publish_post', $item ),
				'delete_item'        => current_user_can( 'delete_post', $item ),
				'assign_menu'        => current_user_can( 'assign_term', $menu ),
				'edit_menu'          => current_user_can( 'edit_term', $menu ),
				'delete_menu'        => current_user_can( 'delete_term', $menu ),
				'edit_theme_options' => current_user_can( 'edit_theme_options' ),
			),
			$write
		);
	}

	/**
	 * A menu with one published and one draft item, and the agent that may change it.
	 *
	 * @return array{menu: int, live: int, draft: int}
	 */
	private function fixture(): array {
		$menu = $this->classic_menu( 'Steadhand guard menu' );

		return array(
			'menu'  => $menu,
			'live'  => $this->custom_item( $menu, 'Live', 'https://example.test/live/', 0, 1 ),
			'draft' => $this->draft_custom_item( $menu, 'Hidden', 'https://example.test/hidden/', 0, 2 ),
		);
	}

	/**
	 * A nav_menu_item in the given status, in the given menus (none when empty). Returns its post ID.
	 *
	 * @param string $status Post status.
	 * @param int[]  $menus  Menu term IDs.
	 */
	private function menu_item_in( string $status, array $menus ): int {
		$item = wp_insert_post(
			array(
				'post_type'   => 'nav_menu_item',
				'post_status' => $status,
				'post_title'  => 'Odd item ' . $status,
			)
		);
		$this->assertGreaterThan( 0, $item, 'The item fixture must be created.' );
		if ( array() !== $menus ) {
			wp_set_object_terms( (int) $item, $menus, 'nav_menu' );
		}

		return (int) $item;
	}

	/**
	 * Positive control: a dedicated agent holding steadhand_menus and steadhand_publish may edit a published or a draft item of exactly one
	 * classic menu, and assign that menu, inside steadhand/update-menu. The same agent is refused edit_theme_options.
	 */
	public function test_positive_control_inside_update_menu_the_agent_may_edit_its_items_and_assign_its_menu(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		foreach ( array( $fixture['live'], $fixture['draft'] ) as $item ) {
			$answers = $this->answers_inside( self::UPDATE, $item, $fixture['menu'] );
			$this->assertTrue( $answers['edit_item'], "edit_post on item $item inside update-menu." );
			$this->assertTrue( $answers['assign_menu'], 'assign_term on its menu inside update-menu.' );
			$this->assertFalse( $answers['edit_theme_options'], 'edit_theme_options is never granted.' );
		}
	}

	/**
	 * Core's edit_theme_options answer is never granted, inside update-menu or anywhere: the Customizer, widgets and theme mods
	 * stay closed to the call.
	 */
	public function test_edit_theme_options_is_refused_inside_update_menu(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertFalse( $this->answers_inside( self::UPDATE, $fixture['live'], $fixture['menu'] )['edit_theme_options'] );
		$this->assertFalse( (bool) $this->inside( self::UPDATE, static fn(): bool => current_user_can( 'edit_theme_options' ) ) );
	}

	/**
	 * Inside every other write call and inside a read, a menu item and its menu get no grant, whatever the agent holds.
	 */
	public function test_edit_post_on_a_menu_item_is_refused_inside_every_other_ability_and_inside_a_read(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
				Capabilities::TRASH   => true,
			)
		);

		foreach ( array( 'steadhand/update-post', 'steadhand/trash-post', 'steadhand/restore-post', 'steadhand/create-post', 'steadhand/undo-change' ) as $ability ) {
			$answers = $this->answers_inside( $ability, $fixture['live'], $fixture['menu'] );
			$this->assertFalse( $answers['edit_item'], "edit_post on a menu item inside $ability." );
			$this->assertFalse( $answers['assign_menu'], "assign_term on a menu inside $ability." );
		}

		$read = $this->answers_inside( 'steadhand/get-menu', $fixture['live'], $fixture['menu'], false );
		$this->assertFalse( $read['edit_item'], 'edit_post on a menu item inside a read.' );
		$this->assertFalse( $read['assign_menu'], 'assign_term on a menu inside a read.' );
	}

	/**
	 * Outside any call, the menu item and the menu are refused, for the agent holding both capabilities.
	 */
	public function test_edit_post_on_a_menu_item_is_refused_outside_a_call(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertSame( '', Pipeline::current_ability_name() );
		$this->assertFalse( current_user_can( 'edit_post', $fixture['live'] ) );
		$this->assertFalse( current_user_can( 'edit_post', $fixture['draft'] ) );
		$this->assertFalse( current_user_can( 'assign_term', $fixture['menu'] ) );
	}

	/**
	 * Outside update-menu, assign_term on a menu is refused: outside any call, and inside another write call.
	 */
	public function test_assign_term_on_a_menu_is_refused_outside_update_menu(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertFalse( current_user_can( 'assign_term', $fixture['menu'] ), 'assign_term outside a call.' );
		$this->assertFalse( (bool) $this->inside( 'steadhand/update-post', static fn(): bool => current_user_can( 'assign_term', $fixture['menu'] ) ), 'assign_term inside update-post.' );
	}

	/**
	 * Renaming and deleting a menu are refused inside update-menu. Only assign_term is granted on a menu.
	 */
	public function test_edit_term_and_delete_term_on_a_menu_are_refused_inside_update_menu(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$answers = $this->answers_inside( self::UPDATE, $fixture['live'], $fixture['menu'] );
		$this->assertFalse( $answers['edit_menu'], 'edit_term on the menu.' );
		$this->assertFalse( $answers['delete_menu'], 'delete_term on the menu.' );
	}

	/**
	 * Inside update-menu, a menu item is never read, published or deleted by the mapping. Those answers stay refused.
	 */
	public function test_delete_post_read_post_and_publish_post_on_a_menu_item_are_refused_inside_update_menu(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$answers = $this->answers_inside( self::UPDATE, $fixture['live'], $fixture['menu'] );
		$this->assertFalse( $answers['read_item'], 'read_post on a menu item.' );
		$this->assertFalse( $answers['publish_item'], 'publish_post on a menu item.' );
		$this->assertFalse( $answers['delete_item'], 'delete_post on a menu item.' );
	}

	/**
	 * The grant needs both steadhand_menus and steadhand_publish. Each one alone is refused, for the item and for the menu.
	 */
	public function test_the_grant_needs_both_steadhand_menus_and_steadhand_publish(): void {
		$fixture = $this->fixture();

		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => false,
			)
		);
		$without_publish = $this->answers_inside( self::UPDATE, $fixture['live'], $fixture['menu'] );
		$this->assertFalse( $without_publish['edit_item'], 'Without steadhand_publish, edit_post on an item.' );
		$this->assertFalse( $without_publish['assign_menu'], 'Without steadhand_publish, assign_term on the menu.' );

		$this->act_as_agent(
			array(
				Capabilities::MENUS   => false,
				Capabilities::PUBLISH => true,
			)
		);
		$without_menus = $this->answers_inside( self::UPDATE, $fixture['live'], $fixture['menu'] );
		$this->assertFalse( $without_menus['edit_item'], 'Without steadhand_menus, edit_post on an item.' );
		$this->assertFalse( $without_menus['assign_menu'], 'Without steadhand_menus, assign_term on the menu.' );

		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$both = $this->answers_inside( self::UPDATE, $fixture['live'], $fixture['menu'] );
		$this->assertTrue( $both['edit_item'], 'Control: with both, edit_post on an item.' );
	}

	/**
	 * An earlier map_meta_cap filter's do_not_allow stays one. The menu branches never turn it into a grant.
	 */
	public function test_an_earlier_do_not_allow_is_never_turned_into_a_grant(): void {
		$fixture = $this->fixture();
		$this->map_filter(
			static fn( array $caps, string $cap ): array => in_array( $cap, array( 'edit_post', 'assign_term' ), true ) ? array( 'do_not_allow' ) : $caps,
			1
		);
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$answers = $this->answers_inside( self::UPDATE, $fixture['live'], $fixture['menu'] );
		$this->assertFalse( $answers['edit_item'], 'An earlier do_not_allow on edit_post.' );
		$this->assertFalse( $answers['assign_menu'], 'An earlier do_not_allow on assign_term.' );
	}

	/**
	 * Core asking for anything beyond the type's own capabilities refuses the grant, for an item and for a menu.
	 */
	public function test_an_extra_core_requirement_refuses_the_grant(): void {
		$fixture = $this->fixture();
		$this->map_filter(
			static function ( array $caps, string $cap ): array {
				if ( in_array( $cap, array( 'edit_post', 'assign_term' ), true ) ) {
					$caps[] = 'manage_options';
				}

				return $caps;
			},
			1
		);
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$answers = $this->answers_inside( self::UPDATE, $fixture['live'], $fixture['menu'] );
		$this->assertFalse( $answers['edit_item'], 'An extra requirement on edit_post refuses the item.' );
		$this->assertFalse( $answers['assign_menu'], 'An extra requirement on assign_term refuses the menu.' );
	}

	/**
	 * A read call nested in update-menu gets no grant. The outer call keeps its own grant after the nested call returns.
	 */
	public function test_a_nested_read_inside_update_menu_gets_no_grant(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$item = $fixture['live'];
		$menu = $fixture['menu'];

		$outer = $this->inside(
			self::UPDATE,
			function () use ( $item, $menu ): array {
				$nested = $this->answers_inside( 'steadhand/get-menu', $item, $menu, false );

				return array(
					'nested_edit'   => $nested['edit_item'],
					'nested_assign' => $nested['assign_menu'],
					'outer_edit'    => current_user_can( 'edit_post', $item ),
					'outer_assign'  => current_user_can( 'assign_term', $menu ),
				);
			}
		);

		$this->assertFalse( $outer['nested_edit'], 'edit_post inside a nested read.' );
		$this->assertFalse( $outer['nested_assign'], 'assign_term inside a nested read.' );
		$this->assertTrue( $outer['outer_edit'], 'The outer call keeps edit_post after the nested read.' );
		$this->assertTrue( $outer['outer_assign'], 'The outer call keeps assign_term after the nested read.' );
	}

	/**
	 * Assign_term on a category is not the menu grant. Only a nav_menu term with core's exact answer is granted.
	 */
	public function test_assign_term_on_a_category_is_not_mapped(): void {
		$fixture  = $this->fixture();
		$category = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Not a menu',
			)
		);
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertFalse( $this->answers_inside( self::UPDATE, $fixture['live'], $category )['assign_menu'], 'assign_term on a category inside update-menu.' );
	}

	/**
	 * A user who is not a dedicated agent, switched in during the call, keeps core's answers. Core grants an administrator both.
	 */
	public function test_a_non_agent_gets_core_answers_inside_the_call(): void {
		$fixture = $this->fixture();
		$admin   = $this->admin_id();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$item = $fixture['live'];
		$menu = $fixture['menu'];

		$answers = $this->inside(
			self::UPDATE,
			static function () use ( $admin, $item, $menu ): array {
				$agent = get_current_user_id();
				wp_set_current_user( $admin );
				try {
					return array(
						'edit'   => current_user_can( 'edit_post', $item ),
						'assign' => current_user_can( 'assign_term', $menu ),
					);
				} finally {
					wp_set_current_user( $agent );
				}
			}
		);

		$this->assertTrue( $answers['edit'], 'Core answers edit_post for an administrator, and Steadhand does not replace it.' );
		$this->assertTrue( $answers['assign'], 'Core answers assign_term for an administrator, and Steadhand does not replace it.' );
	}

	/**
	 * A menu item that is in another status, in no menu or in two menus, or is trashed, gets no grant inside update-menu.
	 */
	public function test_edit_post_is_refused_inside_update_menu_for_another_status_and_for_an_item_in_no_menu_or_two(): void {
		$fixture = $this->fixture();
		$second  = $this->classic_menu( 'Steadhand second menu' );
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$cases = array(
			'pending'   => $this->menu_item_in( 'pending', array( $fixture['menu'] ) ),
			'private'   => $this->menu_item_in( 'private', array( $fixture['menu'] ) ),
			'trash'     => $this->menu_item_in( 'trash', array( $fixture['menu'] ) ),
			'no menu'   => $this->menu_item_in( 'publish', array() ),
			'two menus' => $this->menu_item_in( 'publish', array( $fixture['menu'], $second ) ),
		);

		foreach ( $cases as $case => $item ) {
			$this->assertFalse( $this->answers_inside( self::UPDATE, $item, $fixture['menu'] )['edit_item'], "edit_post for an item: $case." );
		}
	}

	/**
	 * Core answers edit_post_meta, add_post_meta and delete_post_meta from edit_post. Inside update-menu, all three are refused on a menu
	 * item for every key: a protected one and an ordinary one alike.
	 */
	public function test_post_meta_capabilities_on_a_menu_item_are_refused_inside_update_menu_for_protected_and_unprotected_keys(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$item = $fixture['live'];

		foreach ( array( 'steadhand_note', '_steadhand_note' ) as $key ) {
			$answers = $this->inside(
				self::UPDATE,
				static fn(): array => array(
					'edit'   => current_user_can( 'edit_post_meta', $item, $key ),
					'add'    => current_user_can( 'add_post_meta', $item, $key ),
					'delete' => current_user_can( 'delete_post_meta', $item, $key ),
				)
			);

			$this->assertSame(
				array(
					'edit'   => false,
					'add'    => false,
					'delete' => false,
				),
				$answers,
				"Post meta on a menu item for the key $key inside update-menu."
			);
		}
	}

	/**
	 * A draft menu item is refused for read_post inside update-menu, as a published one is. Only edit_post is granted, for a draft too.
	 */
	public function test_read_post_on_a_draft_menu_item_is_refused_inside_update_menu(): void {
		$fixture = $this->fixture();
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$answers = $this->answers_inside( self::UPDATE, $fixture['draft'], $fixture['menu'] );
		$this->assertFalse( $answers['read_item'], 'read_post on a draft menu item.' );
		$this->assertTrue( $answers['edit_item'], 'Control: edit_post on the draft menu item.' );
	}

	/**
	 * The post meta refusal is for classic menu items only. On an ordinary draft, an unprotected key keeps core's answer inside update-menu.
	 */
	public function test_post_meta_on_a_normal_post_keeps_core_answers_inside_update_menu(): void {
		$fixture = $this->fixture();
		$post    = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$answers = $this->inside(
			self::UPDATE,
			static fn(): array => array(
				'edit' => current_user_can( 'edit_post_meta', $post, 'steadhand_note' ),
				'add'  => current_user_can( 'add_post_meta', $post, 'steadhand_note' ),
			)
		);

		$this->assertTrue( $answers['edit'], 'edit_post_meta on an ordinary draft, which the agent may edit.' );
		$this->assertTrue( $answers['add'], 'add_post_meta on an ordinary draft, which the agent may edit.' );
		$this->assertNotEmpty( $fixture['menu'] );
	}
}
