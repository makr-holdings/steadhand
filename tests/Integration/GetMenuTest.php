<?php
/**
 * The steadhand/get-menu ability, end to end over MCP, for both classic menus and block navigation.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\MenuReader;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §5 (menus), §10 and §14.1 for the single-menu tool.
 */
final class GetMenuTest extends MenuToolTestCase {

	private const TOOL_NAME = 'steadhand-get-menu';
	private const REFUSED   = 'Not found, or this agent may not access it.';

	/**
	 * Calls get-menu and returns the tool result.
	 *
	 * @param string $kind classic or block.
	 * @param int    $id   Menu term ID or navigation post ID.
	 * @return array<string, mixed>
	 */
	private function menu( string $kind, int $id ): array {
		return $this->tool_result(
			self::TOOL_NAME,
			array(
				'kind' => $kind,
				'id'   => $id,
			)
		);
	}

	public function test_a_classic_menu_returns_its_items_flat_in_order_with_parents_types_objects_and_urls(): void {
		$this->act_as_agent();
		$page     = $this->admin_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'About page',
			)
		);
		$category = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News desk',
			)
		);
		$menu     = $this->classic_menu( 'Steadhand flat' );
		$home     = $this->custom_item( $menu, 'Home', 'https://example.test/', 0, 1 );
		$about    = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page,
				'menu-item-title'     => 'About us',
				'menu-item-status'    => 'publish',
				'menu-item-position'  => 2,
			)
		);
		$team     = $this->custom_item( $menu, 'Team', 'https://example.test/team/', $about, 3 );
		$news     = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => 'category',
				'menu-item-object-id' => $category,
				'menu-item-status'    => 'publish',
				'menu-item-position'  => 4,
			)
		);
		$this->assign_location( $menu );

		$result = $this->menu( 'classic', $menu );
		$output = $result['structuredContent'];

		$this->assertFalse( $result['isError'] );
		$this->assertSame(
			array( 'kind', 'id', 'name', 'slug', 'locations', 'items', 'movable', 'content', 'blocks', 'truncated' ),
			array_keys( $output )
		);
		$this->assertSame( 'classic', $output['kind'] );
		$this->assertSame( $menu, $output['id'] );
		$this->assertSame( 'Steadhand flat', $output['name'] );
		$this->assertSame( array( self::LOCATION ), $output['locations'] );
		$this->assertNull( $output['content'] );
		$this->assertNull( $output['blocks'] );
		$this->assertFalse( $output['truncated'] );

		$items = $output['items'];
		$this->assertSame( array( $home, $about, $team, $news ), array_column( $items, 'id' ) );
		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $items, 'order' ) );
		$this->assertSame( array( 'custom', 'post_type', 'custom', 'taxonomy' ), array_column( $items, 'type' ) );
		$this->assertSame( array( '', 'page', '', 'category' ), array_column( $items, 'object' ) );
		$this->assertSame( array( 0, $page, 0, $category ), array_column( $items, 'object_id' ) );
		$this->assertSame( array( 0, 0, $about, 0 ), array_column( $items, 'parent' ), 'Team is a child of About; the others are top level.' );
		$this->assertSame( 'About us', $items[1]['title'] );
		$this->assertSame( get_permalink( $page ), $items[1]['url'] );
		$this->assertSame( 'https://example.test/team/', $items[2]['url'] );
		$this->assertSame( get_term_link( $category ), $items[3]['url'] );
		$this->assertSame( 'News desk', $items[3]['title'] );
		$this->assertSame( array( 'id', 'title', 'url', 'type', 'object', 'object_id', 'parent', 'order', 'target', 'classes', 'description', 'hidden' ), array_keys( $items[0] ) );
		$this->assertSame( array( false, false, false, false ), array_column( $items, 'hidden' ), 'Published items are not hidden.' );
	}

	public function test_an_item_whose_page_is_in_the_trash_is_skipped(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand invalid' );
		$kept = $this->custom_item( $menu, 'Kept', 'https://example.test/kept/' );
		$gone = $this->admin_post( array( 'post_type' => 'page' ) );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $gone,
				'menu-item-status'    => 'publish',
			)
		);
		wp_trash_post( $gone );

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);

		$this->assertSame( array( $kept ), array_column( $output['items'], 'id' ) );
		$this->assertFalse( $output['truncated'] );
	}

	public function test_more_than_five_hundred_items_are_capped_and_truncated_is_set(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand long' );
		for ( $i = 1; $i <= 501; $i++ ) {
			$this->custom_item( $menu, 'Item ' . $i, 'https://example.test/' . $i, 0, $i );
		}

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);

		$this->assertCount( 500, $output['items'] );
		$this->assertTrue( $output['truncated'] );
	}

	public function test_a_classic_menu_without_a_location_has_no_locations(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand loose' );

		$this->assertSame(
			array(),
			$this->output(
				self::TOOL_NAME,
				array(
					'kind' => 'classic',
					'id'   => $menu,
				)
			)['locations']
		);
	}

	public function test_a_missing_menu_a_category_id_and_a_block_kind_for_a_classic_id_get_the_same_refusal(): void {
		$this->act_as_agent();
		$menu     = $this->classic_menu( 'Steadhand refusal' );
		$category = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Not a menu',
			)
		);

		$texts = array(
			'missing'          => $this->refusal_text( $this->menu( 'classic', 999999999 ) ),
			'category_as_menu' => $this->refusal_text( $this->menu( 'classic', $category ) ),
			'block_kind'       => $this->refusal_text( $this->menu( 'block', $menu ) ),
		);

		$this->assertSame( array_fill( 0, 3, self::REFUSED ), array_values( $texts ) );
	}

	/**
	 * Core's lookup is not the only guard. A filter on wp_get_nav_menu_object() that returns a term of another
	 * taxonomy must still be refused, because the tool checks the taxonomy itself.
	 */
	public function test_a_classic_id_that_a_filter_resolves_to_a_term_of_another_taxonomy_is_refused(): void {
		$this->act_as_agent();
		$category = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Filtered category',
			)
		);
		add_filter(
			'wp_get_nav_menu_object',
			static fn( $menu, $arg ) => (int) $arg === $category ? get_term( $category ) : $menu,
			10,
			2
		);

		try {
			$this->assertSame( self::REFUSED, $this->refusal_text( $this->menu( 'classic', $category ) ) );
		} finally {
			remove_all_filters( 'wp_get_nav_menu_object' );
		}
	}

	/**
	 * The same guard for the term ID: a filter that returns a different menu for an ID must not be followed.
	 */
	public function test_a_classic_id_that_a_filter_resolves_to_a_different_menu_is_refused(): void {
		$this->act_as_agent();
		$requested = $this->classic_menu( 'Requested menu' );
		$other     = $this->classic_menu( 'Other menu' );
		add_filter(
			'wp_get_nav_menu_object',
			static fn( $menu, $arg ) => (int) $arg === $requested ? get_term( $other, 'nav_menu' ) : $menu,
			10,
			2
		);

		try {
			$this->assertSame( self::REFUSED, $this->refusal_text( $this->menu( 'classic', $requested ) ) );
		} finally {
			remove_all_filters( 'wp_get_nav_menu_object' );
		}
	}

	public function test_block_navigation_returns_the_content_as_stored_and_its_block_tree(): void {
		$this->act_as_agent();
		$content = "<!-- wp:navigation-link {\"label\":\"Home\",\"url\":\"/\"} /-->\n"
			. "<!-- wp:navigation-submenu {\"label\":\"More\"} -->\n"
			. "<!-- wp:navigation-link {\"label\":\"Sub\",\"url\":\"/sub\"} /-->\n"
			. '<!-- /wp:navigation-submenu -->';
		$nav     = $this->block_navigation( 'publish', $content, 'Main navigation' );
		$this->assertSame( $content, get_post( $nav )->post_content, 'Control: the fixture is stored as written.' );

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'block',
				'id'   => $nav,
			)
		);

		$this->assertSame( 'block', $output['kind'] );
		$this->assertSame( $nav, $output['id'] );
		$this->assertSame( 'Main navigation', $output['name'] );
		$this->assertSame( get_post( $nav )->post_name, $output['slug'] );
		$this->assertSame( array(), $output['locations'] );
		$this->assertNull( $output['items'] );
		$this->assertSame( $content, $output['content'] );
		$this->assertFalse( $output['truncated'] );
		$this->assertSame( array( '0', '1', '1.0' ), $this->paths( $output['blocks'] ) );
		$this->assertSame( 'core/navigation-link', $output['blocks'][0]['name'] );
		$this->assertSame( 'core/navigation-submenu', $output['blocks'][1]['name'] );
		$this->assertSame( 'core/navigation-link', $output['blocks'][1]['inner_blocks'][0]['name'] );
		$this->assertSame(
			array(
				'label' => 'Home',
				'url'   => '/',
			),
			(array) $output['blocks'][0]['attrs']
		);
	}

	/**
	 * Every path in a block tree, depth first.
	 *
	 * @param array<int, array<string, mixed>> $blocks Block nodes.
	 * @return string[]
	 */
	private function paths( array $blocks ): array {
		$paths = array();
		foreach ( $blocks as $block ) {
			$paths[] = $block['path'];
			$paths   = array_merge( $paths, $this->paths( $block['inner_blocks'] ) );
		}

		return $paths;
	}

	public function test_block_navigation_content_over_the_limit_is_cut_and_flagged(): void {
		$this->act_as_agent();
		$content = str_repeat( "\xe2\x82\xac", 100000 );
		$nav     = $this->block_navigation( 'publish', $content );

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'block',
				'id'   => $nav,
			)
		);

		$this->assertTrue( $output['truncated'] );
		$this->assertSame( 262143, strlen( $output['content'] ) );
		$this->assertSame( 1, preg_match( '//u', $output['content'] ) );
	}

	public function test_trashed_auto_draft_regular_post_and_wp_block_ids_get_the_same_refusal_as_a_missing_one(): void {
		$this->act_as_agent();
		$texts = array(
			'missing'      => $this->refusal_text( $this->menu( 'block', 999999999 ) ),
			'trashed'      => $this->refusal_text( $this->menu( 'block', $this->block_navigation( 'trash', '', 'Trashed' ) ) ),
			'auto_draft'   => $this->refusal_text( $this->menu( 'block', $this->block_navigation( 'auto-draft', '', 'Auto' ) ) ),
			'regular_post' => $this->refusal_text( $this->menu( 'block', $this->admin_post() ) ),
			'wp_block'     => $this->refusal_text( $this->menu( 'block', $this->admin_post( array( 'post_type' => 'wp_block' ) ) ) ),
		);

		$this->assertCount( 5, $texts );
		$this->assertSame( array_fill( 0, 5, self::REFUSED ), array_values( $texts ) );
	}

	public function test_an_agent_without_steadhand_read_is_refused_for_both_kinds(): void {
		$this->act_as_agent( array( Capabilities::READ => false ) );
		$menu = $this->classic_menu( 'Hidden classic' );
		$nav  = $this->block_navigation( 'publish', 'Hidden block content', 'Hidden block' );

		foreach ( array( array( 'classic', $menu ), array( 'block', $nav ) ) as list( $kind, $id ) ) {
			$result = $this->menu( $kind, $id );
			$text   = $this->refusal_text( $result );

			$this->assertStringContainsString( 'steadhand_read', $text, $kind );
			$this->assertStringNotContainsString( 'Hidden block content', $text, $kind );
		}
	}

	public function test_the_pipeline_is_not_running_after_a_call(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Pipeline menu' );

		$this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);
		$this->menu( 'block', 999999999 );

		$this->assertFalse( Pipeline::is_running() );
	}

	/**
	 * Calls get-menu for a classic menu and returns its items.
	 *
	 * @param int $menu Menu term ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function items_of( int $menu ): array {
		return $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		)['items'];
	}

	public function test_a_draft_item_is_returned_with_hidden_true_and_a_published_one_with_hidden_false(): void {
		$this->act_as_agent();
		$menu  = $this->classic_menu( 'Steadhand hidden flag' );
		$live  = $this->custom_item( $menu, 'Shown', 'https://example.test/shown/', 0, 1 );
		$draft = $this->draft_custom_item( $menu, 'Hidden', 'https://example.test/hidden/', 0, 2 );

		$items = $this->items_of( $menu );

		$this->assertSame( array( $live, $draft ), array_column( $items, 'id' ) );
		$this->assertSame( array( false, true ), array_column( $items, 'hidden' ) );
	}

	/**
	 * A menu whose stored published count is 0 still returns its drafts. Core's wp_get_nav_menu_items() would return nothing here.
	 */
	public function test_a_menu_whose_only_items_are_hidden_still_returns_them(): void {
		$this->act_as_agent();
		$menu  = $this->classic_menu( 'Steadhand all hidden' );
		$draft = $this->draft_custom_item( $menu, 'Only hidden', 'https://example.test/only/', 0, 1 );

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);

		$this->assertSame( array( $draft ), array_column( $output['items'], 'id' ) );
		$this->assertTrue( $output['items'][0]['hidden'] );
		$this->assertFalse( $output['truncated'] );
	}

	/**
	 * Core's orphan mark only hides a draft. A published item that carries a stale mark is printed by the public menu, so it is returned.
	 */
	public function test_a_published_item_with_a_stale_orphan_mark_is_returned(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand stale mark' );
		$live = $this->custom_item( $menu, 'Live', 'https://example.test/live/', 0, 1 );
		update_post_meta( $live, '_menu_item_orphaned', (string) time() );

		$items = $this->items_of( $menu );

		$this->assertSame( array( $live ), array_column( $items, 'id' ) );
		$this->assertFalse( $items[0]['hidden'] );
	}

	public function test_an_orphaned_draft_is_not_returned(): void {
		$this->act_as_agent();
		$menu     = $this->classic_menu( 'Steadhand orphan' );
		$live     = $this->custom_item( $menu, 'Kept', 'https://example.test/kept/', 0, 1 );
		$orphaned = $this->draft_custom_item( $menu, 'Orphaned', 'https://example.test/orphaned/', 0, 2 );
		update_post_meta( $orphaned, '_menu_item_orphaned', (string) time() );

		$this->assertSame( array( $live ), array_column( $this->items_of( $menu ), 'id' ) );
	}

	/**
	 * A trashed item is not returned, whether it was published or a draft before it was trashed.
	 */
	public function test_a_trashed_item_is_not_returned(): void {
		$this->act_as_agent();
		$menu  = $this->classic_menu( 'Steadhand trashed item' );
		$live  = $this->custom_item( $menu, 'Kept', 'https://example.test/kept/', 0, 1 );
		$gone  = $this->custom_item( $menu, 'Gone', 'https://example.test/gone/', 0, 2 );
		$draft = $this->draft_custom_item( $menu, 'Gone draft', 'https://example.test/gone-draft/', 0, 3 );
		foreach ( array( $gone, $draft ) as $trashed ) {
			wp_update_post(
				array(
					'ID'          => $trashed,
					'post_status' => 'trash',
				)
			);
		}

		$this->assertSame( array( $live ), array_column( $this->items_of( $menu ), 'id' ) );
	}

	/**
	 * A hidden item's title and URL are the ones a published item gets: for an item linked to a page, the page's title and permalink,
	 * and for a custom link, its own title and URL.
	 */
	public function test_a_hidden_items_title_and_url_match_the_published_treatment(): void {
		$this->act_as_agent();
		$page   = $this->admin_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Shared page',
			)
		);
		$menu   = $this->classic_menu( 'Steadhand same treatment' );
		$live   = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page,
				'menu-item-status'    => 'publish',
				'menu-item-position'  => 1,
			)
		);
		$hidden = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page,
				'menu-item-status'    => 'draft',
				'menu-item-position'  => 2,
			)
		);
		$custom = $this->draft_custom_item( $menu, 'Hidden &amp; <b>plain</b>', 'https://example.test/plain/', 0, 3 );

		$items = array_column( $this->items_of( $menu ), null, 'id' );

		$this->assertSame( 'Shared page', $items[ $hidden ]['title'] );
		$this->assertSame( get_permalink( $page ), $items[ $hidden ]['url'] );
		$this->assertSame( $items[ $live ]['title'], $items[ $hidden ]['title'] );
		$this->assertSame( $items[ $live ]['url'], $items[ $hidden ]['url'] );
		$this->assertSame( 'Hidden & plain', $items[ $custom ]['title'], 'The title is plain text, as for a published item.' );
		$this->assertSame( 'https://example.test/plain/', $items[ $custom ]['url'] );
		$this->assertFalse( $items[ $live ]['hidden'] );
		$this->assertTrue( $items[ $hidden ]['hidden'] );
	}

	/**
	 * A hidden item whose linked page is in the trash is left out, as a published one is.
	 */
	public function test_a_hidden_item_whose_page_is_in_the_trash_is_skipped(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand hidden invalid' );
		$kept = $this->draft_custom_item( $menu, 'Kept hidden', 'https://example.test/kept-hidden/', 0, 1 );
		$gone = $this->admin_post( array( 'post_type' => 'page' ) );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $gone,
				'menu-item-status'    => 'draft',
			)
		);
		wp_trash_post( $gone );

		$this->assertSame( array( $kept ), array_column( $this->items_of( $menu ), 'id' ) );
	}

	public function test_no_output_key_matches_a_secret_name_for_either_kind(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Scan menu' );
		$this->custom_item( $menu, 'Scan item', 'https://example.test/' );
		$nav = $this->block_navigation( 'publish', '<!-- wp:paragraph --><p>Scan</p><!-- /wp:paragraph -->' );

		$keys = array_merge(
			$this->keys_at_any_depth( $this->menu( 'classic', $menu ) ),
			$this->keys_at_any_depth( $this->menu( 'block', $nav ) )
		);

		$this->assertDoesNotMatchRegularExpression( '/password|email|login|user_pass/i', implode( ' ', $keys ) );
	}

	/**
	 * Calls get-menu for a classic menu with the wp_get_nav_menu_items filter set to $filter, and returns the items.
	 *
	 * @param int      $menu   Menu term ID.
	 * @param callable $filter The filter callback, which takes (array $items, \WP_Term $menu, array $args).
	 * @return array<int, array<string, mixed>>
	 */
	private function items_through_filter( int $menu, callable $filter ): array {
		add_filter( 'wp_get_nav_menu_items', $filter, 10, 3 );
		try {
			return $this->items_of( $menu );
		} finally {
			remove_all_filters( 'wp_get_nav_menu_items' );
		}
	}

	public function test_a_filter_that_drops_an_item_drops_it_from_get_menu(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand filter drop' );
		$keep = $this->custom_item( $menu, 'Keep', 'https://example.test/keep/', 0, 1 );
		$drop = $this->draft_custom_item( $menu, 'Drop', 'https://example.test/drop/', 0, 2 );

		$items = $this->items_through_filter(
			$menu,
			static fn( array $items ): array => array_values( array_filter( $items, static fn( $item ): bool => (int) $item->ID !== $drop ) )
		);

		$this->assertSame( array( $keep ), array_column( $items, 'id' ) );
	}

	/**
	 * A filter may not add an item. The entries it may add are ignored: a WP_Post of another menu (its ID was not passed in), and a
	 * string and a number (not objects). An object that is not a WP_Post, even one that carries a kept ID, is ignored too. A WP_Post
	 * that carries a kept ID is accepted by design: it is the item the filter was given, possibly changed. The items of this menu
	 * come back unchanged.
	 */
	public function test_a_filter_that_adds_foreign_objects_gets_them_ignored(): void {
		$this->act_as_agent();
		$menu    = $this->classic_menu( 'Steadhand filter add' );
		$keep    = $this->custom_item( $menu, 'Keep', 'https://example.test/keep/', 0, 1 );
		$draft   = $this->draft_custom_item( $menu, 'Draft', 'https://example.test/draft/', 0, 2 );
		$other   = $this->classic_menu( 'Steadhand filter other' );
		$foreign = $this->custom_item( $other, 'Foreign', 'https://example.test/foreign/', 0, 1 );

		$items = $this->items_through_filter(
			$menu,
			static function ( array $items ) use ( $foreign, $keep ): array {
				$items[] = get_post( $foreign );
				$items[] = (object) array(
					'ID'        => $keep,
					'post_type' => 'nav_menu_item',
					'title'     => 'Impostor',
				);
				$items[] = 'not an object';
				$items[] = 42;

				return $items;
			}
		);

		$this->assertSame( array( $keep, $draft ), array_column( $items, 'id' ) );
		$this->assertSame( array( 'Keep', 'Draft' ), array_column( $items, 'title' ) );
	}

	/**
	 * A menu of exactly 500 items returns all 500, and truncated is false: the limit is inclusive.
	 */
	public function test_a_menu_of_exactly_500_items_returns_all_of_them_untruncated(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand exactly 500' );
		for ( $i = 1; $i <= 500; $i++ ) {
			$this->custom_item( $menu, 'Item ' . $i, 'https://example.test/e/' . $i, 0, $i );
		}

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);

		$this->assertCount( 500, $output['items'] );
		$this->assertFalse( $output['truncated'] );
	}

	/**
	 * Current behaviour, asserted as it is: a menu of 501 items where a wp_get_nav_menu_items filter removes one returns the other 500,
	 * and truncated stays true. The reader stops at 501 kept items before the filter runs, and truncated is decided from that count, so
	 * the removal does not clear it. Nothing beyond the 501 is read.
	 */
	public function test_a_filter_that_removes_one_of_501_items_leaves_500_and_truncated_stays_true(): void {
		$this->act_as_agent();
		$menu  = $this->classic_menu( 'Steadhand 501 filtered' );
		$first = 0;
		for ( $i = 1; $i <= 501; $i++ ) {
			$id = $this->custom_item( $menu, 'Item ' . $i, 'https://example.test/f/' . $i, 0, $i );
			if ( 1 === $i ) {
				$first = $id;
			}
		}

		$items = $this->items_through_filter(
			$menu,
			static fn( array $items ): array => array_values( array_filter( $items, static fn( $item ): bool => (int) $item->ID !== $first ) )
		);

		$this->assertCount( 500, $items );
		$this->assertNotContains( $first, array_column( $items, 'id' ) );
		$this->assertTrue(
			$this->output(
				self::TOOL_NAME,
				array(
					'kind' => 'classic',
					'id'   => $menu,
				)
			)['truncated'],
			'Control: without the filter the menu is truncated.'
		);
	}

	/**
	 * A filter may change a title. The changed title goes through the same plain-text and length treatment as any title.
	 */
	public function test_a_filter_that_changes_a_title_gets_the_plain_text_and_length_treatment(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand filter title' );
		$keep = $this->custom_item( $menu, 'Keep', 'https://example.test/keep/', 0, 1 );

		$items = $this->items_through_filter(
			$menu,
			static function ( array $items ) use ( $keep ): array {
				foreach ( $items as $item ) {
					if ( (int) $item->ID === $keep ) {
						$item->title = '<b>Changed</b> &amp; ' . str_repeat( "\u{00e9}", 1500 );
					}
				}

				return $items;
			}
		);

		$this->assertSame( 1, count( $items ) );
		$this->assertStringStartsWith( 'Changed & ', $items[0]['title'] );
		$this->assertLessThanOrEqual( 1000, strlen( $items[0]['title'] ) );
		$this->assertSame( 1, preg_match( '//u', $items[0]['title'] ), 'The title stays valid UTF-8.' );
	}

	/**
	 * A filter that returns something other than an array means no items. The call does not fail.
	 */
	public function test_a_filter_that_returns_a_non_array_gives_an_empty_list(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand filter scalar' );
		$this->custom_item( $menu, 'Gone', 'https://example.test/gone/', 0, 1 );

		$this->assertSame( array(), $this->items_through_filter( $menu, static fn(): string => 'nothing' ) );
	}

	/**
	 * A pre_wp_setup_nav_menu_item filter that returns a modified item sets the title and URL get-menu prints.
	 */
	public function test_a_pre_wp_setup_filter_that_returns_a_modified_item_is_used(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand pre setup' );
		$keep = $this->custom_item( $menu, 'Original', 'https://example.test/original/', 0, 1 );
		add_filter(
			'pre_wp_setup_nav_menu_item',
			static function ( $pre, $menu_item ) use ( $keep ) {
				if ( $menu_item instanceof \WP_Post && (int) $menu_item->ID === $keep ) {
					$menu_item->type  = 'custom';
					$menu_item->title = 'From the pre filter';
					$menu_item->url   = 'https://example.test/pre/';

					return $menu_item;
				}

				return $pre;
			},
			10,
			2
		);

		try {
			$items = $this->items_of( $menu );
		} finally {
			remove_all_filters( 'pre_wp_setup_nav_menu_item' );
		}

		$this->assertSame( 'From the pre filter', $items[0]['title'] );
		$this->assertSame( 'https://example.test/pre/', $items[0]['url'] );
	}

	/**
	 * A published child under a draft parent is returned as visible, with the stored parent ID, and the draft parent is returned as hidden.
	 */
	public function test_a_published_child_under_a_draft_parent_is_returned_with_the_parent_id(): void {
		$this->act_as_agent();
		$menu   = $this->classic_menu( 'Steadhand draft parent' );
		$parent = $this->draft_custom_item( $menu, 'Hidden parent', 'https://example.test/parent/', 0, 1 );
		$child  = $this->custom_item( $menu, 'Visible child', 'https://example.test/child/', $parent, 2 );

		$items = array_column( $this->items_of( $menu ), null, 'id' );

		$this->assertTrue( $items[ $parent ]['hidden'] );
		$this->assertFalse( $items[ $child ]['hidden'] );
		$this->assertSame( $parent, $items[ $child ]['parent'] );
	}

	/**
	 * A stored parent that is negative or not a whole number is returned as 0, and the item is still listed.
	 */
	public function test_a_negative_or_non_numeric_stored_parent_is_returned_as_zero(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand bad parent' );
		$neg  = $this->custom_item( $menu, 'Negative', 'https://example.test/neg/', 0, 1 );
		$text = $this->custom_item( $menu, 'Text', 'https://example.test/text/', 0, 2 );
		update_post_meta( $neg, '_menu_item_menu_item_parent', '-5' );
		update_post_meta( $text, '_menu_item_menu_item_parent', '12abc' );

		$items = array_column( $this->items_of( $menu ), null, 'id' );

		$this->assertSame( array( $neg, $text ), array_keys( $items ) );
		$this->assertSame( 0, $items[ $neg ]['parent'] );
		$this->assertSame( 0, $items[ $text ]['parent'] );
	}

	/**
	 * Order and truncated on a menu that mixes draft and published items: order counts the hidden items, and a short menu is not truncated.
	 */
	public function test_order_counts_hidden_items_and_a_mixed_short_menu_is_not_truncated(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand mixed order' );
		$a    = $this->custom_item( $menu, 'A', 'https://example.test/a/', 0, 1 );
		$b    = $this->draft_custom_item( $menu, 'B', 'https://example.test/b/', 0, 2 );
		$c    = $this->custom_item( $menu, 'C', 'https://example.test/c/', 0, 3 );

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);

		$this->assertSame( array( $a, $b, $c ), array_column( $output['items'], 'id' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $output['items'], 'order' ) );
		$this->assertSame( array( false, true, false ), array_column( $output['items'], 'hidden' ) );
		$this->assertFalse( $output['truncated'] );
	}

	/**
	 * A menu of more than 500 items that mixes drafts and published items is cut to 500, and truncated is true.
	 */
	public function test_a_mixed_menu_over_the_limit_is_cut_to_500_and_truncated(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand mixed long' );
		for ( $i = 1; $i <= 501; $i++ ) {
			if ( 1 === $i % 100 ) {
				$this->draft_custom_item( $menu, 'Draft ' . $i, 'https://example.test/d/' . $i, 0, $i );
			} else {
				$this->custom_item( $menu, 'Item ' . $i, 'https://example.test/i/' . $i, 0, $i );
			}
		}

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);

		$this->assertCount( 500, $output['items'] );
		$this->assertTrue( $output['truncated'] );
		$this->assertSame( range( 1, 500 ), array_column( $output['items'], 'order' ) );
	}

	/**
	 * The number of queries to read a menu does not grow with its items. A 30-item menu costs about what a 10-item menu costs.
	 */
	public function test_the_number_of_queries_does_not_grow_with_the_number_of_items(): void {
		$this->act_as_agent();

		$measure = function ( int $count ): int {
			$menu = $this->classic_menu( 'Steadhand queries ' . $count );
			for ( $i = 1; $i <= $count; $i++ ) {
				$page = $this->admin_post(
					array(
						'post_type'  => 'page',
						'post_title' => "Query page $count-$i",
					)
				);
				wp_update_nav_menu_item(
					$menu,
					0,
					array(
						'menu-item-type'      => 'post_type',
						'menu-item-object'    => 'page',
						'menu-item-object-id' => $page,
						'menu-item-status'    => 0 === $i % 3 ? 'draft' : 'publish',
						'menu-item-position'  => $i,
					)
				);
			}

			wp_cache_flush();
			$term   = get_term( $menu, 'nav_menu' );
			$before = $GLOBALS['wpdb']->num_queries;
			$page   = MenuReader::page( $term );
			foreach ( $page['items'] as $item ) {
				MenuReader::item( $item );
			}

			$this->assertCount( $count, $page['items'], 'Control: every item is read.' );

			return $GLOBALS['wpdb']->num_queries - $before;
		};

		$small = $measure( 10 );
		$large = $measure( 30 );

		$this->assertLessThanOrEqual( $small + 3, $large, "10 items took $small queries, 30 items took $large." );
	}
}
