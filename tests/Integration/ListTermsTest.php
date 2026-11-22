<?php
/**
 * The steadhand/list-terms ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §5 (terms), §10 and §14.1 for the term listing tool.
 */
final class ListTermsTest extends ReadToolTestCase {

	private const TOOL_NAME = 'steadhand-list-terms';
	private const GENRE     = 'steadhand_genre';

	/**
	 * Removes the custom taxonomy and any filter this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'steadhand_allowed_post_types' );
		if ( taxonomy_exists( self::GENRE ) ) {
			unregister_taxonomy( self::GENRE );
		}
		parent::tearDown();
	}

	/**
	 * Names of the items in a list output, in order.
	 *
	 * @param array<string, mixed> $output Structured output.
	 * @return string[]
	 */
	private function names( array $output ): array {
		return array_column( $output['items'], 'name' );
	}

	/**
	 * Creates a tag and returns its term ID.
	 *
	 * @param string $name Tag name.
	 */
	private function tag( string $name ): int {
		$term = wp_insert_term( $name, 'post_tag' );
		$this->assertIsArray( $term, 'The tag fixture must be created: ' . $name );

		return (int) $term['term_id'];
	}

	public function test_lists_categories_and_tags_including_the_empty_ones(): void {
		$this->act_as_agent();
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Empty category',
			)
		);
		$this->tag( 'Lonely tag' );

		$categories = $this->output(
			self::TOOL_NAME,
			array(
				'taxonomy' => 'category',
				'per_page' => 100,
			)
		);
		$tags       = $this->output( self::TOOL_NAME, array( 'taxonomy' => 'post_tag' ) );

		$this->assertContains( 'Empty category', $this->names( $categories ) );
		$this->assertContains( 'Lonely tag', $this->names( $tags ) );
		$empty = array_values( array_filter( $categories['items'], static fn( array $item ): bool => 'Empty category' === $item['name'] ) );
		$this->assertSame( 0, $empty[0]['count'] );
		$this->assertSame( 'category', $empty[0]['taxonomy'] );
	}

	public function test_search_matches_names_within_the_taxonomy(): void {
		$this->act_as_agent();
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Lighthouse',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Harbour',
			)
		);

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'taxonomy' => 'category',
				'search'   => 'Light',
			)
		);

		$this->assertSame( array( 'Lighthouse' ), $this->names( $output ) );
		$this->assertSame( 1, $output['total'] );
	}

	public function test_parent_lists_the_children_and_zero_lists_only_top_level_terms(): void {
		$this->act_as_agent();
		$parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Parent category',
			)
		);
		$child  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Child category',
				'parent'   => $parent,
			)
		);

		$children = $this->output(
			self::TOOL_NAME,
			array(
				'taxonomy' => 'category',
				'parent'   => $parent,
			)
		);
		$top      = $this->output(
			self::TOOL_NAME,
			array(
				'taxonomy' => 'category',
				'parent'   => 0,
				'per_page' => 100,
			)
		);

		$this->assertSame( array( 'Child category' ), $this->names( $children ) );
		$this->assertSame( $parent, $children['items'][0]['parent'] );
		$this->assertSame( $child, $children['items'][0]['id'] );
		$this->assertContains( 'Parent category', $this->names( $top ) );
		$this->assertNotContains( 'Child category', $this->names( $top ) );
	}

	public function test_orders_by_name_count_and_id_in_both_directions(): void {
		$this->act_as_agent();
		$this->tag( 'Bravo' );
		$this->tag( 'Alpha' );
		$this->tag( 'Charlie' );
		$first  = $this->admin_post();
		$second = $this->admin_post();
		wp_set_post_terms( $first, array( 'Bravo', 'Charlie' ), 'post_tag' );
		wp_set_post_terms( $second, array( 'Bravo' ), 'post_tag' );

		$by = fn( array $args ): array => $this->names( $this->output( self::TOOL_NAME, array_merge( array( 'taxonomy' => 'post_tag' ), $args ) ) );

		$this->assertSame( array( 'Alpha', 'Bravo', 'Charlie' ), $by( array() ), 'Default is name ascending.' );
		$this->assertSame(
			array( 'Alpha', 'Bravo', 'Charlie' ),
			$by(
				array(
					'orderby' => 'name',
					'order'   => 'asc',
				)
			)
		);
		$this->assertSame(
			array( 'Charlie', 'Bravo', 'Alpha' ),
			$by(
				array(
					'orderby' => 'name',
					'order'   => 'desc',
				)
			)
		);
		$this->assertSame(
			array( 'Bravo', 'Charlie', 'Alpha' ),
			$by(
				array(
					'orderby' => 'count',
					'order'   => 'desc',
				)
			)
		);
		$this->assertSame(
			array( 'Alpha', 'Charlie', 'Bravo' ),
			$by(
				array(
					'orderby' => 'count',
					'order'   => 'asc',
				)
			)
		);
		$this->assertSame(
			array( 'Bravo', 'Alpha', 'Charlie' ),
			$by(
				array(
					'orderby' => 'id',
					'order'   => 'asc',
				)
			)
		);
		$this->assertSame(
			array( 'Charlie', 'Alpha', 'Bravo' ),
			$by(
				array(
					'orderby' => 'id',
					'order'   => 'desc',
				)
			)
		);
	}

	public function test_pagination_counts_every_term_and_a_page_past_the_end_is_empty(): void {
		$this->act_as_agent();
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->tag( sprintf( 'Tag %02d', $i ) );
		}

		$page1 = $this->output(
			self::TOOL_NAME,
			array(
				'taxonomy' => 'post_tag',
				'per_page' => 2,
				'page'     => 1,
			)
		);
		$page3 = $this->output(
			self::TOOL_NAME,
			array(
				'taxonomy' => 'post_tag',
				'per_page' => 2,
				'page'     => 3,
			)
		);
		$page4 = $this->output(
			self::TOOL_NAME,
			array(
				'taxonomy' => 'post_tag',
				'per_page' => 2,
				'page'     => 4,
			)
		);

		$this->assertSame( array( 'Tag 01', 'Tag 02' ), $this->names( $page1 ) );
		$this->assertSame( 5, $page1['total'] );
		$this->assertSame( 3, $page1['total_pages'] );
		$this->assertSame( 1, $page1['page'] );
		$this->assertSame( 2, $page1['per_page'] );
		$this->assertSame( array( 'Tag 05' ), $this->names( $page3 ) );
		$this->assertSame( array(), $page4['items'] );
		$this->assertSame( 5, $page4['total'], 'A page past the end still reports the full total.' );
		$this->assertSame( 3, $page4['total_pages'] );
		$this->assertSame( 4, $page4['page'] );
	}

	public function test_an_empty_taxonomy_reports_zero_total_and_zero_pages(): void {
		$this->act_as_agent();

		$output = $this->output( self::TOOL_NAME, array( 'taxonomy' => 'post_tag' ) );

		$this->assertSame( array(), $output['items'] );
		$this->assertSame( 0, $output['total'] );
		$this->assertSame( 0, $output['total_pages'] );
	}

	public function test_nav_menu_link_category_unregistered_and_private_taxonomies_are_refused_with_the_allowed_list(): void {
		register_taxonomy( self::GENRE, 'post', array( 'public' => false ) );
		$this->act_as_agent();

		foreach ( array( 'nav_menu', 'link_category', 'steadhand_missing', self::GENRE ) as $taxonomy ) {
			$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'taxonomy' => $taxonomy ) ) );

			$this->assertStringContainsString( 'must be one of the taxonomies Steadhand allows', $text, $taxonomy );
			$this->assertStringContainsString( 'category', $text, $taxonomy );
			$this->assertStringContainsString( 'post_tag', $text, $taxonomy );
		}
	}

	public function test_a_public_custom_taxonomy_on_post_is_listed(): void {
		register_taxonomy( self::GENRE, 'post', array( 'public' => true ) );
		wp_insert_term( 'Mystery', self::GENRE );
		$this->act_as_agent();

		$output = $this->output( self::TOOL_NAME, array( 'taxonomy' => self::GENRE ) );

		$this->assertSame( array( 'Mystery' ), $this->names( $output ) );
		$this->assertSame( self::GENRE, $output['items'][0]['taxonomy'] );
	}

	public function test_a_public_taxonomy_on_a_custom_type_is_listed_only_once_the_type_is_allowed(): void {
		register_post_type( 'steadhand_book', array( 'public' => true ) );
		register_taxonomy( self::GENRE, 'steadhand_book', array( 'public' => true ) );
		wp_insert_term( 'Mystery', self::GENRE );
		$this->act_as_agent();

		$this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'taxonomy' => self::GENRE ) ) );

		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( 'steadhand_book' ) )
		);
		try {
			$this->assertSame( array( 'Mystery' ), $this->names( $this->output( self::TOOL_NAME, array( 'taxonomy' => self::GENRE ) ) ) );
		} finally {
			remove_all_filters( 'steadhand_allowed_post_types' );
			unregister_post_type( 'steadhand_book' );
		}
	}

	public function test_the_description_is_plain_text_and_capped_on_a_utf8_boundary(): void {
		$this->act_as_agent();
		wp_insert_term( 'Markup', 'post_tag', array( 'description' => '<p>Tom &amp; <b>Jerry</b></p>' ) );
		wp_insert_term( 'Long', 'post_tag', array( 'description' => str_repeat( "\xe2\x82\xac", 1000 ) ) );

		$output = $this->output( self::TOOL_NAME, array( 'taxonomy' => 'post_tag' ) );
		$by     = array_column( $output['items'], null, 'name' );

		$this->assertSame( 'Tom & Jerry', $by['Markup']['description'] );
		$this->assertSame( 1998, strlen( $by['Long']['description'] ), 'The cut stops before the first incomplete character.' );
		$this->assertSame( 1, preg_match( '//u', $by['Long']['description'] ) );
		$this->assertSame( str_repeat( "\xe2\x82\xac", 666 ), $by['Long']['description'] );
	}

	public function test_bounds_and_unknown_properties_are_refused(): void {
		$this->act_as_agent();
		$cases = array(
			array(
				'taxonomy' => 'category',
				'per_page' => 101,
			),
			array(
				'taxonomy' => 'category',
				'per_page' => 0,
			),
			array(
				'taxonomy' => 'category',
				'page'     => 0,
			),
			array(
				'taxonomy' => 'category',
				'page'     => 10001,
			),
			array(
				'taxonomy' => 'category',
				'order'    => 'sideways',
			),
			array(
				'taxonomy' => 'category',
				'orderby'  => 'slug',
			),
			array(
				'taxonomy' => 'category',
				'parent'   => -1,
			),
			array(
				'taxonomy' => 'category',
				'search'   => str_repeat( 'a', 201 ),
			),
			array( 'taxonomy' => str_repeat( 'a', 33 ) ),
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
			),
			array(
				'taxonomy' => 'category',
				'parent'   => 'top',
			),
		);

		foreach ( $cases as $arguments ) {
			$this->refusal_text( $this->tool_result( self::TOOL_NAME, $arguments ) );
		}
	}

	public function test_taxonomy_is_required(): void {
		$this->act_as_agent();

		$this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'per_page' => 5 ) ) );
	}

	public function test_an_agent_without_steadhand_read_gets_the_capability_refusal_and_not_the_allowed_list(): void {
		$this->act_as_agent( array( Capabilities::READ => false ) );

		foreach ( array( 'nav_menu', 'steadhand_missing', 'category' ) as $taxonomy ) {
			$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'taxonomy' => $taxonomy ) ) );

			$this->assertStringContainsString( 'steadhand_read', $text, $taxonomy );
			$this->assertStringNotContainsString( 'allows', $text, $taxonomy );
			$this->assertStringNotContainsString( 'post_tag', $text, $taxonomy );
		}
	}

	public function test_the_transport_refuses_an_administrator_even_with_every_steadhand_grant(): void {
		wp_set_current_user( $this->admin_id() );

		$response = $this->mcp(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'steadhand-tests',
					'version' => '1',
				),
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_the_pipeline_is_not_running_after_a_call(): void {
		$this->act_as_agent();

		$this->output( self::TOOL_NAME, array( 'taxonomy' => 'category' ) );
		$this->tool_result( self::TOOL_NAME, array( 'taxonomy' => 'nav_menu' ) );

		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_no_output_key_matches_a_secret_name(): void {
		$this->act_as_agent();
		$this->tag( 'Scan tag' );

		$result = $this->tool_result( self::TOOL_NAME, array( 'taxonomy' => 'post_tag' ) );
		$keys   = implode( ' ', $this->keys_at_any_depth( $result ) );

		$this->assertDoesNotMatchRegularExpression( '/password|email|login|user_pass/i', $keys );
	}
}
