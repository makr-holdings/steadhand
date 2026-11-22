<?php
/**
 * The steadhand/get-menu ability, end to end over MCP, for both classic menus and block navigation.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

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
			array( 'kind', 'id', 'name', 'slug', 'locations', 'items', 'content', 'blocks', 'truncated' ),
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
		$this->assertSame( array( 'id', 'title', 'url', 'type', 'object', 'object_id', 'parent', 'order', 'target', 'classes', 'description' ), array_keys( $items[0] ) );
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
}
