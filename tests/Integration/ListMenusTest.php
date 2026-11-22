<?php
/**
 * The steadhand/list-menus ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §5 (menus) and §14.1 for the menu listing tool: both kinds of menu, their status rules and the cap.
 */
final class ListMenusTest extends MenuToolTestCase {

	private const TOOL_NAME = 'steadhand-list-menus';

	/**
	 * Menu entries of an output, keyed by name.
	 *
	 * @param array<string, mixed> $output Structured output.
	 * @return array<string, array<string, mixed>>
	 */
	private function by_name( array $output ): array {
		return array_column( $output['menus'], null, 'name' );
	}

	public function test_an_empty_site_lists_no_menus(): void {
		$this->act_as_agent();

		$this->assertSame(
			array(
				'menus'     => array(),
				'truncated' => false,
			),
			$this->output( self::TOOL_NAME )
		);
	}

	public function test_a_classic_menu_reports_its_items_and_location_and_an_unassigned_menu_has_none(): void {
		$this->act_as_agent();
		$main = $this->classic_menu( 'Steadhand main' );
		$this->custom_item( $main, 'Home', 'https://example.test/', 0, 1 );
		$this->custom_item( $main, 'Blog', 'https://example.test/blog/', 0, 2 );
		$spare = $this->classic_menu( 'Steadhand spare' );
		$this->assign_location( $main );

		$menus = $this->by_name( $this->output( self::TOOL_NAME ) );

		$this->assertSame(
			array(
				'kind'       => 'classic',
				'id'         => $main,
				'name'       => 'Steadhand main',
				'slug'       => get_term( $main, 'nav_menu' )->slug,
				'item_count' => 2,
				'locations'  => array( self::LOCATION ),
				'status'     => 'publish',
			),
			$menus['Steadhand main']
		);
		$this->assertSame( array(), $menus['Steadhand spare']['locations'] );
		$this->assertSame( 0, $menus['Steadhand spare']['item_count'] );
		$this->assertSame( $spare, $menus['Steadhand spare']['id'] );
	}

	public function test_block_navigation_in_publish_and_draft_is_listed_and_trash_and_auto_draft_are_not(): void {
		$this->act_as_agent();
		$published = $this->block_navigation( 'publish', '', 'Published nav' );
		$draft     = $this->block_navigation( 'draft', '', 'Draft nav' );
		$this->block_navigation( 'trash', '', 'Trashed nav' );
		$this->block_navigation( 'auto-draft', '', 'Auto nav' );

		$menus = $this->by_name( $this->output( self::TOOL_NAME ) );

		$this->assertEqualsCanonicalizing( array( 'Published nav', 'Draft nav' ), array_keys( $menus ) );
		$this->assertSame(
			array(
				'kind'       => 'block',
				'id'         => $published,
				'name'       => 'Published nav',
				'slug'       => get_post( $published )->post_name,
				'item_count' => null,
				'locations'  => array(),
				'status'     => 'publish',
			),
			$menus['Published nav']
		);
		$this->assertSame( 'draft', $menus['Draft nav']['status'] );
		$this->assertSame( $draft, $menus['Draft nav']['id'] );
	}

	public function test_a_classic_menu_and_block_navigation_together_are_listed_classic_first_and_the_trashed_one_is_not(): void {
		$this->act_as_agent();
		$this->classic_menu( 'Steadhand classic' );
		$this->block_navigation( 'publish', '', 'Steadhand block' );
		$this->block_navigation( 'trash', '', 'Steadhand trashed' );

		$output = $this->output( self::TOOL_NAME );

		$this->assertSame( array( 'classic', 'block' ), array_column( $output['menus'], 'kind' ) );
		$this->assertFalse( $output['truncated'] );
	}

	public function test_more_than_two_hundred_menus_are_capped_and_truncated_is_set(): void {
		$this->act_as_agent();
		for ( $i = 1; $i <= 201; $i++ ) {
			$this->classic_menu( 'Cap menu ' . $i );
		}

		$output = $this->output( self::TOOL_NAME );

		$this->assertCount( 200, $output['menus'] );
		$this->assertTrue( $output['truncated'] );
		$this->assertSame( array_fill( 0, 200, 'classic' ), array_column( $output['menus'], 'kind' ) );
	}

	public function test_exactly_two_hundred_menus_are_not_truncated_and_block_navigation_counts_toward_the_cap(): void {
		$this->act_as_agent();
		for ( $i = 1; $i <= 199; $i++ ) {
			$this->classic_menu( 'Edge menu ' . $i );
		}
		$this->block_navigation( 'publish', '', 'Edge block' );

		$output = $this->output( self::TOOL_NAME );

		$this->assertCount( 200, $output['menus'] );
		$this->assertFalse( $output['truncated'] );
		$this->assertSame( 'block', $output['menus'][199]['kind'] );
	}

	public function test_block_navigation_beyond_the_cap_is_left_out_and_truncated_is_set(): void {
		$this->act_as_agent();
		for ( $i = 1; $i <= 200; $i++ ) {
			$this->classic_menu( 'Full menu ' . $i );
		}
		$this->block_navigation( 'publish', '', 'Over the cap' );

		$output = $this->output( self::TOOL_NAME );

		$this->assertCount( 200, $output['menus'] );
		$this->assertTrue( $output['truncated'] );
		$this->assertNotContains( 'block', array_column( $output['menus'], 'kind' ) );
	}

	public function test_an_agent_without_steadhand_read_is_refused(): void {
		$this->act_as_agent( array( Capabilities::READ => false ) );
		$this->classic_menu( 'Hidden menu' );

		$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME ) );

		$this->assertStringContainsString( 'steadhand_read', $text );
		$this->assertStringNotContainsString( 'Hidden menu', $text );
	}

	public function test_the_pipeline_is_not_running_after_a_call(): void {
		$this->act_as_agent();

		$this->output( self::TOOL_NAME );

		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_no_output_key_matches_a_secret_name(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Scan menu' );
		$this->custom_item( $menu, 'Scan item', 'https://example.test/' );
		$this->block_navigation( 'publish', '', 'Scan block' );

		$keys = implode( ' ', $this->keys_at_any_depth( $this->tool_result( self::TOOL_NAME ) ) );

		$this->assertDoesNotMatchRegularExpression( '/password|email|login|user_pass/i', $keys );
	}

	public function test_item_count_is_the_stored_term_count_and_no_item_is_set_up_to_count_them(): void {
		$this->act_as_agent();
		$main = $this->classic_menu( 'Steadhand counted' );
		$this->custom_item( $main, 'One', 'https://example.test/1', 0, 1 );
		$this->custom_item( $main, 'Two', 'https://example.test/2', 0, 2 );
		$this->custom_item( $main, 'Three', 'https://example.test/3', 0, 3 );
		$setups = 0;
		add_filter(
			'wp_setup_nav_menu_item',
			static function ( $item ) use ( &$setups ) {
				++$setups;
				return $item;
			}
		);

		$menus = $this->by_name( $this->output( self::TOOL_NAME ) );
		remove_all_filters( 'wp_setup_nav_menu_item' );

		$this->assertSame( 3, $menus['Steadhand counted']['item_count'] );
		$this->assertSame( (int) get_term( $main, 'nav_menu' )->count, $menus['Steadhand counted']['item_count'] );
		$this->assertSame( 0, $setups, 'list-menus must not load and set up the items of any menu.' );
	}
}
