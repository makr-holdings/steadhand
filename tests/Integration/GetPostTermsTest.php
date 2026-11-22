<?php
/**
 * The steadhand/get-post-terms ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §5 (terms), §10 and §14.1 for the post-terms tool.
 */
final class GetPostTermsTest extends ReadToolTestCase {

	private const TOOL_NAME = 'steadhand-get-post-terms';
	private const PRIVATE   = 'steadhand_shelf';

	/**
	 * Removes the taxonomy and any filter this file adds.
	 */
	protected function tearDown(): void {
		if ( taxonomy_exists( self::PRIVATE ) ) {
			unregister_taxonomy( self::PRIVATE );
		}
		parent::tearDown();
	}

	/**
	 * The taxonomy entry for one name in an output, or null.
	 *
	 * @param array<string, mixed> $output   Structured output.
	 * @param string               $taxonomy Taxonomy name.
	 * @return array<string, mixed>|null
	 */
	private function entry( array $output, string $taxonomy ): ?array {
		foreach ( $output['taxonomies'] as $entry ) {
			if ( $taxonomy === $entry['taxonomy'] ) {
				return $entry;
			}
		}

		return null;
	}

	public function test_a_post_with_two_categories_and_three_tags_is_grouped_and_ordered_by_name(): void {
		$this->act_as_agent();
		$beta  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Beta',
			)
		);
		$alpha = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Alpha',
			)
		);
		$post  = $this->admin_post();
		wp_set_post_terms( $post, array( $beta, $alpha ), 'category' );
		wp_set_post_terms( $post, array( 'Zulu', 'Echo', 'Mike' ), 'post_tag' );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $post ) );

		$this->assertSame( $post, $output['id'] );
		$this->assertSame( array( 'category', 'post_format', 'post_tag' ), array_column( $output['taxonomies'], 'taxonomy' ), 'Taxonomy names ascending.' );

		$categories = $this->entry( $output, 'category' );
		$this->assertSame( 'Categories', $categories['label'] );
		$this->assertSame( array( 'Alpha', 'Beta' ), array_column( $categories['terms'], 'name' ) );
		$this->assertSame( array( $alpha, $beta ), array_column( $categories['terms'], 'id' ) );
		$this->assertSame( array( 'alpha', 'beta' ), array_column( $categories['terms'], 'slug' ) );
		$this->assertSame( array( 0, 0 ), array_column( $categories['terms'], 'parent' ) );

		$tags = $this->entry( $output, 'post_tag' );
		$this->assertSame( array( 'Echo', 'Mike', 'Zulu' ), array_column( $tags['terms'], 'name' ) );
	}

	public function test_a_hierarchical_child_reports_its_parent_term_id(): void {
		$this->act_as_agent();
		$parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Parent',
			)
		);
		$child  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Child',
				'parent'   => $parent,
			)
		);
		$post   = $this->admin_post();
		wp_set_post_terms( $post, array( $child ), 'category' );

		$terms = $this->entry( $this->output( self::TOOL_NAME, array( 'id' => $post ) ), 'category' )['terms'];

		$this->assertSame( $child, $terms[0]['id'] );
		$this->assertSame( $parent, $terms[0]['parent'] );
	}

	public function test_a_page_has_no_default_taxonomies_so_the_list_is_empty(): void {
		$this->act_as_agent();
		$page = $this->admin_post( array( 'post_type' => 'page' ) );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $page ) );

		$this->assertSame( array(), $output['taxonomies'] );
	}

	public function test_an_assigned_post_with_no_terms_has_an_entry_with_an_empty_terms_array(): void {
		$this->act_as_agent();
		$post = $this->admin_post();

		$output = $this->output( self::TOOL_NAME, array( 'id' => $post ) );

		$this->assertSame( array( 'category', 'post_format', 'post_tag' ), array_column( $output['taxonomies'], 'taxonomy' ) );
		$this->assertSame( array(), $this->entry( $output, 'post_tag' )['terms'] );
	}

	public function test_a_draft_by_another_author_is_readable(): void {
		$this->act_as_agent();
		$tag   = wp_insert_term( 'Draft tag', 'post_tag' );
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_post_terms( $draft, array( (int) $tag['term_id'] ), 'post_tag' );

		$terms = $this->entry( $this->output( self::TOOL_NAME, array( 'id' => $draft ) ), 'post_tag' )['terms'];

		$this->assertSame( array( 'Draft tag' ), array_column( $terms, 'name' ) );
	}

	public function test_trashed_attachment_missing_custom_css_and_other_refusals_share_one_text(): void {
		$this->act_as_agent();

		$texts = $this->single_post_refusal_texts( self::TOOL_NAME );

		$this->assertCount( 9, $texts );
		$this->assertCount( 1, array_unique( array_values( $texts ) ), 'Every refusal must read the same: ' . wp_json_encode( $texts ) );
		$this->assertStringContainsString( 'Not found, or this agent may not access it.', $texts['missing'] );
	}

	public function test_terms_of_a_taxonomy_that_is_not_allowed_never_appear(): void {
		register_taxonomy( self::PRIVATE, 'post', array( 'public' => false ) );
		$this->act_as_agent();
		$post = $this->admin_post();
		wp_set_post_terms( $post, array( 'Secret shelf' ), self::PRIVATE );
		wp_set_post_terms( $post, array( 'Visible tag' ), 'post_tag' );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $post ) );

		$this->assertNotContains( self::PRIVATE, array_column( $output['taxonomies'], 'taxonomy' ) );
		$this->assertStringNotContainsString( 'Secret shelf', (string) wp_json_encode( $output ) );
		$this->assertSame( array( 'Visible tag' ), array_column( $this->entry( $output, 'post_tag' )['terms'], 'name' ) );
	}

	public function test_a_refused_call_returns_no_terms(): void {
		$this->act_as_agent( array( Capabilities::READ => false ) );
		$post = $this->admin_post();
		wp_set_post_terms( $post, array( 'Hidden tag' ), 'post_tag' );

		$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $post ) ) );

		$this->assertStringNotContainsString( 'Hidden tag', $text );
		$this->assertStringContainsString( 'steadhand_read', $text );
	}

	public function test_ids_that_are_zero_negative_or_not_integers_are_refused(): void {
		$this->act_as_agent();

		foreach ( array( 0, -1, 1.5, 'abc' ) as $id ) {
			$this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $id ) ) );
		}
	}

	public function test_the_pipeline_is_not_running_after_a_call(): void {
		$this->act_as_agent();
		$post = $this->admin_post();

		$this->output( self::TOOL_NAME, array( 'id' => $post ) );
		$this->tool_result( self::TOOL_NAME, array( 'id' => 999999999 ) );

		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_no_output_key_matches_a_secret_name(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		wp_set_post_terms( $post, array( 'Scan tag' ), 'post_tag' );

		$keys = implode( ' ', $this->keys_at_any_depth( $this->tool_result( self::TOOL_NAME, array( 'id' => $post ) ) ) );

		$this->assertDoesNotMatchRegularExpression( '/password|email|login|user_pass/i', $keys );
	}

	public function test_a_post_with_520_tags_returns_500_with_truncated_set_and_the_total(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		$tags = self::factory()->term->create_many( 520, array( 'taxonomy' => 'post_tag' ) );
		wp_set_object_terms( $post, array_map( 'intval', $tags ), 'post_tag' );

		$tag_entry = $this->entry( $this->output( self::TOOL_NAME, array( 'id' => $post ) ), 'post_tag' );

		$this->assertCount( 500, $tag_entry['terms'] );
		$this->assertTrue( $tag_entry['truncated'] );
		$this->assertSame( 520, $tag_entry['total'] );
	}

	public function test_exactly_500_terms_are_not_truncated_and_a_taxonomy_with_no_terms_reports_zero(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		$tags = self::factory()->term->create_many( 500, array( 'taxonomy' => 'post_tag' ) );
		wp_set_object_terms( $post, array_map( 'intval', $tags ), 'post_tag' );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $post ) );

		$this->assertCount( 500, $this->entry( $output, 'post_tag' )['terms'] );
		$this->assertFalse( $this->entry( $output, 'post_tag' )['truncated'] );
		$this->assertSame( 500, $this->entry( $output, 'post_tag' )['total'] );
		$this->assertSame( count( $this->entry( $output, 'category' )['terms'] ), $this->entry( $output, 'category' )['total'] );
		$this->assertFalse( $this->entry( $output, 'category' )['truncated'] );
	}

	public function test_the_terms_query_asks_for_at_most_500_terms(): void {
		$this->act_as_agent();
		$post   = $this->admin_post();
		$limits = array();
		add_filter(
			'wp_get_object_terms_args',
			static function ( array $args ) use ( &$limits ): array {
				$limits[] = $args['number'] ?? null;
				return $args;
			}
		);

		$this->output( self::TOOL_NAME, array( 'id' => $post ) );

		remove_all_filters( 'wp_get_object_terms_args' );
		$this->assertContains( 500, $limits, 'The terms must be fetched with a limit, not loaded and sliced.' );
	}
}
