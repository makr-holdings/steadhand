<?php
/**
 * Tests for the add operation of steadhand/update-menu. Mostly negative: the linked post's status and type, the URL and the title, the
 * parent, the depth and the size of the menu, and that the item is never on the public site half built.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\MenuRules;

/**
 * Each test runs the ability through the pipeline, as a dedicated agent with steadhand_menus and steadhand_publish.
 */
final class UpdateMenuAddTest extends MenuWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * A custom post type that is registered but not allowed by Steadhand.
	 */
	private const TYPE_NOT_ALLOWED = 'sh_menu_disallowed';

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
	 * Unregisters the test post type and checks that no permanent delete happened.
	 */
	protected function tearDown(): void {
		if ( post_type_exists( self::TYPE_NOT_ALLOWED ) ) {
			unregister_post_type( self::TYPE_NOT_ALLOWED );
		}
		$this->assertSame( 0, $this->deletes, 'update-menu must never call wp_delete_post().' );
		parent::tearDown();
	}

	/**
	 * A classic menu with one published item, the menu's term ID and the item's ID.
	 *
	 * @return array{menu: int, item: int}
	 */
	private function menu_with_one_item(): array {
		$menu = $this->classic_menu( 'Add ' . wp_generate_password( 6, false ) );

		return array(
			'menu' => $menu,
			'item' => $this->custom_item( $menu, 'Existing', 'https://example.com/existing' ),
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
	 * Makes the current user a menu agent: steadhand_menus and steadhand_publish, with the other capabilities it has by default.
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
	 * The number of items in a menu, published or draft, as the rules count them.
	 *
	 * @param int $menu The menu.
	 */
	private function count_items( int $menu ): int {
		return count( MenuRules::items_raw( $this->menu_term( $menu ) ) );
	}

	/**
	 * A post link to a post that is not published is refused with the generic text: a draft, a pending post, a private post, and a
	 * trashed post. The menu would print the post's title and link whatever its status.
	 */
	public function test_add_refuses_a_draft_pending_private_and_trashed_post(): void {
		$fixture = $this->menu_with_one_item();
		$this->menu_agent();

		foreach ( array( 'draft', 'pending', 'private', 'trash' ) as $status ) {
			$post   = $this->admin_post(
				array(
					'post_status' => $status,
					'post_title'  => "A $status post",
				)
			);
			$result = $this->execute_ability(
				self::ABILITY,
				array(
					'kind'    => 'classic',
					'menu'    => $fixture['menu'],
					'op'      => 'add',
					'type'    => 'post',
					'post_id' => $post,
				)
			);

			$this->assert_generic( $result );
		}

		$this->assertSame( 1, $this->count_items( $fixture['menu'] ), 'A refused add must create no item.' );
	}

	/**
	 * A post link to a type Steadhand does not allow, and to an attachment, is refused with the generic text.
	 */
	public function test_add_refuses_a_post_type_that_is_not_allowed_and_an_attachment(): void {
		$fixture = $this->menu_with_one_item();
		$this->menu_agent();

		register_post_type(
			self::TYPE_NOT_ALLOWED,
			array(
				'public'       => true,
				'map_meta_cap' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
		$other      = self::factory()->post->create(
			array(
				'post_type'   => self::TYPE_NOT_ALLOWED,
				'post_status' => 'publish',
				'post_author' => $this->admin_id(),
			)
		);
		$attachment = (int) wp_insert_attachment(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Specification',
				'post_author'    => $this->admin_id(),
			),
			false,
			0
		);

		foreach ( array( $other, $attachment ) as $post ) {
			$result = $this->execute_ability(
				self::ABILITY,
				array(
					'kind'    => 'classic',
					'menu'    => $fixture['menu'],
					'op'      => 'add',
					'type'    => 'post',
					'post_id' => $post,
				)
			);

			$this->assert_generic( $result );
		}

		$this->assertSame( 1, $this->count_items( $fixture['menu'] ) );
	}

	/**
	 * A custom link that is not an absolute http or https URL is refused as invalid input: javascript, data, ftp, a relative path and an
	 * anchor.
	 */
	public function test_add_refuses_javascript_data_ftp_relative_and_anchor_urls(): void {
		$fixture = $this->menu_with_one_item();
		$this->menu_agent();

		foreach ( array( 'javascript:alert(1)', 'data:text/html,hello', 'ftp://example.com/file', '/relative/path', '#section-one' ) as $url ) {
			$result = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'], array( 'url' => $url ) ) );

			$this->assert_refused( $result, 'steadhand_invalid_input' );
		}

		$this->assertSame( 1, $this->count_items( $fixture['menu'] ) );
	}

	/**
	 * A URL with a user name or a password is refused, and so is one that esc_url_raw() would change.
	 */
	public function test_add_refuses_a_url_with_credentials_and_one_that_esc_url_raw_would_change(): void {
		$fixture = $this->menu_with_one_item();
		$this->menu_agent();

		// esc_url_raw() strips a brace, so a URL with one is not the URL it was sent as.
		foreach ( array( 'https://user:secret@example.com/', 'https://example.com/a{b}', 'https://example.com/a b' ) as $url ) {
			$result = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'], array( 'url' => $url ) ) );

			$this->assert_refused( $result, 'steadhand_invalid_input' );
		}

		$this->assertSame( 1, $this->count_items( $fixture['menu'] ) );
	}

	/**
	 * A URL over the length the schema allows is refused as invalid input.
	 */
	public function test_add_refuses_a_url_over_the_length(): void {
		$fixture = $this->menu_with_one_item();
		$this->menu_agent();

		$result = $this->execute_ability(
			self::ABILITY,
			$this->custom_add( $fixture['menu'], array( 'url' => 'https://example.com/' . str_repeat( 'a', 2000 ) ) )
		);

		$this->assert_refused( $result, 'steadhand_invalid_input' );
	}

	/**
	 * A title with markup, or with a bare ampersand, is refused as invalid input, not fixed.
	 */
	public function test_add_refuses_markup_in_the_title(): void {
		$fixture = $this->menu_with_one_item();
		$this->menu_agent();

		foreach ( array( '<b>Bold</b>', 'Tom & Jerry', "Two\nlines", '  padded  ' ) as $title ) {
			$result = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'], array( 'title' => $title ) ) );

			$this->assert_refused( $result, 'steadhand_invalid_input' );
		}

		$this->assertSame( 1, $this->count_items( $fixture['menu'] ) );
	}

	/**
	 * A parent that is hidden is refused: the new item would show at the top level.
	 */
	public function test_add_refuses_a_hidden_parent(): void {
		$fixture = $this->menu_with_one_item();
		$hidden  = $this->draft_custom_item( $fixture['menu'], 'Hidden parent', 'https://example.com/hidden' );
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'], array( 'parent' => $hidden ) ) );

		$this->assert_refused( $result, 'steadhand_refused_hidden_parent' );
		$this->assertSame( 2, $this->count_items( $fixture['menu'] ) );
	}

	/**
	 * A parent from another menu is refused as invalid input, the same as an ID that is not an item at all.
	 */
	public function test_add_refuses_a_parent_of_another_menu(): void {
		$fixture = $this->menu_with_one_item();
		$other   = $this->classic_menu( 'Other ' . wp_generate_password( 6, false ) );
		$foreign = $this->custom_item( $other, 'Foreign', 'https://example.com/foreign' );
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'], array( 'parent' => $foreign ) ) );

		$this->assert_refused( $result, 'steadhand_invalid_input' );
		$this->assertSame( 1, $this->count_items( $fixture['menu'] ) );
	}

	/**
	 * A parent whose stored parent is malformed is refused: the rules do not place items under it.
	 */
	public function test_add_refuses_a_parent_with_a_malformed_stored_parent(): void {
		$fixture = $this->menu_with_one_item();
		update_post_meta( $fixture['item'], '_menu_item_menu_item_parent', 'not-a-number' );
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'], array( 'parent' => $fixture['item'] ) ) );

		$this->assert_refused( $result, 'steadhand_refused_unsupported_item' );
	}

	/**
	 * A child that would be deeper than the menu's levels is refused.
	 */
	public function test_add_refuses_beyond_the_depth_limit(): void {
		$menu  = $this->classic_menu( 'Deep ' . wp_generate_password( 6, false ) );
		$level = 0;
		for ( $depth = 1; $depth <= MenuRules::MAX_DEPTH; $depth++ ) {
			$level = $this->custom_item( $menu, "Level $depth", "https://example.com/level-$depth", $level );
		}
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $menu, array( 'parent' => $level ) ) );

		$this->assert_refused( $result, 'steadhand_refused_menu_depth' );
		$this->assertSame( MenuRules::MAX_DEPTH, $this->count_items( $menu ) );
	}

	/**
	 * A menu of 500 items takes no more: the add is refused as full, and nothing is created.
	 */
	public function test_add_refuses_a_full_menu(): void {
		$menu = $this->classic_menu( 'Full ' . wp_generate_password( 6, false ) );
		for ( $i = 1; $i <= MenuRules::MAX_ITEMS; $i++ ) {
			$this->custom_item( $menu, "Item $i", "https://example.com/item-$i" );
		}
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $menu ) );

		$this->assert_refused( $result, 'steadhand_refused_menu_full' );
		$this->assertSame( MenuRules::MAX_ITEMS, $this->count_items( $menu ) );
	}

	/**
	 * An add stores no CSS classes, no target, no XFN and no description: the item is only what the call sent.
	 */
	public function test_add_stores_no_classes_target_xfn_or_description(): void {
		$fixture = $this->menu_with_one_item();
		$this->menu_agent();

		$output = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'] ) );

		$this->assertIsArray( $output );
		$item = (int) $output['item']['id'];
		$this->assertSame( array(), $output['item']['classes'] );
		$this->assertSame( '', $output['item']['target'] );
		$this->assertSame( '', $output['item']['description'] );
		$this->assertSame( '', (string) get_post_meta( $item, '_menu_item_target', true ) );
		$this->assertSame( '', (string) get_post_meta( $item, '_menu_item_xfn', true ) );
		$this->assertSame( '', trim( (string) get_post_field( 'post_content', $item ) ) );
		$this->assertSame( '', trim( (string) get_post_field( 'post_excerpt', $item ) ) );
	}

	/**
	 * The new item is never on the public site half built: while WordPress is creating it, the public menu does not list it. The probe
	 * runs at the point core inserts the row, and reads the public menu then.
	 */
	public function test_add_never_shows_a_half_built_item(): void {
		$fixture = $this->menu_with_one_item();
		$this->menu_agent();
		$seen    = array();
		$created = 0;

		$this->on(
			'wp_add_nav_menu_item',
			function ( int $menu_id, int $item_id ) use ( &$seen, &$created ): void {
				$created = $item_id;
				$seen    = wp_list_pluck( wp_get_nav_menu_items( $menu_id ), 'ID' );
			},
			10,
			2
		);

		$output = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'] ) );

		$this->assertIsArray( $output );
		$this->assertGreaterThan( 0, $created, 'The probe must see the new row.' );
		$this->assertNotContains( $created, array_map( 'intval', $seen ), 'The public menu must not list the item before it is published.' );
		$this->assertContains( $created, array_map( 'intval', wp_list_pluck( wp_get_nav_menu_items( $fixture['menu'] ), 'ID' ) ) );
	}

	/**
	 * An add that succeeds attributes the item to the agent (the item's author is the agent), and names that in the warnings.
	 */
	public function test_an_add_warns_that_the_item_is_attributed_to_the_agent(): void {
		$fixture = $this->menu_with_one_item();
		$agent   = $this->menu_agent();

		$output = $this->execute_ability( self::ABILITY, $this->custom_add( $fixture['menu'] ) );

		$this->assertIsArray( $output );
		$this->assertSame( $agent, (int) get_post_field( 'post_author', (int) $output['item']['id'] ) );
		$this->assertNotEmpty( array_filter( $output['warnings'], static fn( string $warning ): bool => str_contains( $warning, 'attributed' ) ) );
	}

	/**
	 * A post link to a published post is added with the post's ID as its object, and the post's type.
	 */
	public function test_a_published_post_is_added_as_a_post_link(): void {
		$fixture = $this->menu_with_one_item();
		$post    = $this->admin_post( array( 'post_title' => 'Published post' ) );
		$this->menu_agent();

		$output = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'    => 'classic',
				'menu'    => $fixture['menu'],
				'op'      => 'add',
				'type'    => 'post',
				'post_id' => $post,
			)
		);

		$this->assertIsArray( $output );
		$this->assertSame( 'post', $output['item']['object'] );
		$this->assertSame( $post, $output['item']['object_id'] );
		$this->assertSame( 'Published post', $output['item']['title'] );
		$this->assertFalse( $output['item']['hidden'] );
	}
}
