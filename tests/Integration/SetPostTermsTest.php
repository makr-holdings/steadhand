<?php
/**
 * The steadhand/set-post-terms ability, end to end over MCP: modes, permissions, refusals, the change log and the write.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\SetPostTerms;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;
use WP\MCP\Abilities\McpAbilityExposure;

/**
 * Covers SPEC §5 (set-post-terms), §6.1, §7, §10, §14 (D21) and §14.1. Every refusal test checks that the post's terms
 * are the same afterwards.
 */
final class SetPostTermsTest extends PostWriteTestCase {

	private const TOOL_NAME  = 'steadhand-set-post-terms';
	private const ABILITY    = 'steadhand/set-post-terms';
	private const BOOK_TYPE  = 'steadhand_book';
	private const SHELF      = 'steadhand_shelf_test';
	private const CUSTOM     = 'steadhand_custom_test';
	private const NAVIGATION = 'steadhand_nav_test';
	private const PRIVATE    = 'steadhand_private_test';
	private const CUSTOM_CAP = 'steadhand_shelve';
	private const REFUSAL    = 'Not found, or this agent may not access it.';

	/**
	 * Removes the taxonomies, post types, filters and actions this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		remove_all_actions( 'set_object_terms' );
		foreach ( array( self::SHELF, self::CUSTOM, self::NAVIGATION, self::PRIVATE ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		if ( post_type_exists( self::BOOK_TYPE ) ) {
			unregister_post_type( self::BOOK_TYPE );
		}
		parent::tearDown();
	}

	/**
	 * A new term in a taxonomy, and its ID.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $name     Term name.
	 */
	private function term( string $taxonomy, string $name ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => $taxonomy,
				'name'     => $name,
			)
		);
	}

	/**
	 * The term IDs a post has in one taxonomy, read from the table, ascending.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return int[]
	 */
	private function stored_ids( int $post_id, string $taxonomy ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the assignment the test compares.
			$wpdb->prepare(
				"SELECT tt.term_id FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tt.term_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table names.
				$post_id,
				$taxonomy
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Asserts that a refusal leaves the terms of the post in the taxonomy as they were.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param int[]  $before   The IDs before the call.
	 */
	private function assert_unchanged( int $post_id, string $taxonomy, array $before ): void {
		$this->assertSame( $before, $this->stored_ids( $post_id, $taxonomy ), 'A refused call must change nothing.' );
	}

	/**
	 * Gives a post a set of terms, through core, and returns the IDs it has.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param int[]  $ids      Term IDs.
	 * @return int[]
	 */
	private function assign( int $post_id, string $taxonomy, array $ids ): array {
		wp_set_object_terms( $post_id, $ids, $taxonomy, false );

		return $this->stored_ids( $post_id, $taxonomy );
	}

	/**
	 * The one sequence of calls that the mode tests run: replace, add and remove, on one taxonomy of one draft.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	private function run_mode_sequence( string $taxonomy ): void {
		$this->act_as_agent();
		$post = $this->draft();
		$a    = $this->term( $taxonomy, 'Alpha' );
		$b    = $this->term( $taxonomy, 'Bravo' );
		$c    = $this->term( $taxonomy, 'Charlie' );
		// A new post may already hold the default category, so the starting set is read, not assumed to be empty.
		$start = $this->stored_ids( $post, $taxonomy );

		$replaced = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => $taxonomy,
				'terms'    => array( $a, $b ),
			)
		);
		$this->assertTrue( $replaced['changed'] );
		$this->assertSame( $start, $replaced['previous_term_ids'] );
		$this->assertSame( array( $a, $b ), $replaced['term_ids'] );
		$this->assertSame( array( $a, $b ), $this->stored_ids( $post, $taxonomy ) );

		$added = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => $taxonomy,
				'terms'    => array( $c ),
				'mode'     => 'add',
			)
		);
		$this->assertTrue( $added['changed'] );
		$this->assertSame( array( $a, $b ), $added['previous_term_ids'] );
		$this->assertSame( array( $a, $b, $c ), $added['term_ids'] );
		$this->assertSame( array( $a, $b, $c ), $this->stored_ids( $post, $taxonomy ) );

		$removed = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => $taxonomy,
				'terms'    => array( $a ),
				'mode'     => 'remove',
			)
		);
		$this->assertTrue( $removed['changed'] );
		$this->assertSame( array( $a, $b, $c ), $removed['previous_term_ids'] );
		$this->assertSame( array( $b, $c ), $removed['term_ids'] );
		$this->assertSame( array( $b, $c ), $this->stored_ids( $post, $taxonomy ) );
	}

	/**
	 * A draft fixture, owned by the administrator, so the agent is never its author.
	 *
	 * @param array<string, mixed> $fields Overrides.
	 */
	private function draft( array $fields = array() ): int {
		return $this->admin_post( array_merge( array( 'post_status' => 'draft' ), $fields ) );
	}

	public function test_replace_add_and_remove_work_on_categories_and_on_tags(): void {
		$this->run_mode_sequence( 'category' );
		$this->run_mode_sequence( 'post_tag' );
	}

	public function test_an_empty_replace_clears_the_taxonomy_and_a_category_clear_warns_about_the_default(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$cat  = $this->term( 'category', 'News' );
		$tag  = $this->term( 'post_tag', 'Spring' );
		$this->assign( $post, 'category', array( $cat ) );
		$this->assign( $post, 'post_tag', array( $tag ) );

		$cleared = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'category',
				'terms'    => array(),
			)
		);
		$this->assertTrue( $cleared['changed'] );
		$this->assertSame( array(), $cleared['term_ids'] );
		$this->assertSame( array(), $cleared['terms'] );
		$this->assertSame( array( $cat ), $cleared['previous_term_ids'] );
		$this->assertContains( 'WordPress gives a post with no category the default category the next time the post is saved.', $cleared['warnings'] );

		$tags = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array(),
			)
		);
		$this->assertSame( array(), $tags['term_ids'] );
		$this->assertNotContains( 'WordPress gives a post with no category the default category the next time the post is saved.', $tags['warnings'] );
	}

	public function test_a_call_that_changes_nothing_reports_no_change_and_fires_no_term_hook(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Stable' );
		$this->assign( $post, 'post_tag', array( $tag ) );
		$fired = 0;
		add_action(
			'set_object_terms',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$same    = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);
		$other   = $this->term( 'post_tag', 'Not on the post' );
		$removed = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $other ),
				'mode'     => 'remove',
			)
		);

		$this->assertFalse( $same['changed'] );
		$this->assertSame( array( $tag ), $same['previous_term_ids'] );
		$this->assertSame( array(), $same['warnings'] );
		$this->assertFalse( $removed['changed'], 'Removing a term the post does not have is not a change.' );
		$this->assertSame( 0, $fired, 'No term assignment may be made for a no-op.' );
	}

	public function test_the_output_lists_term_references_in_name_order_and_the_ids_in_ascending_order(): void {
		$this->act_as_agent();
		$post   = $this->draft();
		$zulu   = $this->term( 'post_tag', 'Zulu' );
		$alpha  = $this->term( 'post_tag', 'Alpha' );
		$parent = $this->term( 'category', 'Parent' );
		$child  = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Child',
				'parent'   => $parent,
			)
		);

		$tags = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $zulu, $alpha ),
			)
		);
		$cats = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'category',
				'terms'    => array( $child, $parent ),
			)
		);

		$this->assertSame( array( 'Alpha', 'Zulu' ), array_column( $tags['terms'], 'name' ) );
		$this->assertSame( array( 'id', 'name', 'slug', 'parent' ), array_keys( $tags['terms'][0] ) );
		$this->assertSame( array( $parent, $child ), $cats['term_ids'] );
		$this->assertSame( array( 'Child', 'Parent' ), array_column( $cats['terms'], 'name' ) );
		$this->assertSame( $parent, $cats['terms'][1]['id'] );
		$this->assertSame( $parent, $cats['terms'][0]['parent'], 'A child reports its parent term ID.' );
	}

	public function test_an_agent_without_either_write_capability_is_refused_and_nothing_changes(): void {
		$this->act_as_agent( array( Capabilities::WRITE_DRAFTS => false ) );
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Nope' );

		$text = $this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => array( $tag ),
				)
			)
		);

		$this->assertStringContainsString( 'steadhand_write_drafts', $text );
		$this->assert_unchanged( $post, 'post_tag', array() );
	}

	public function test_a_drafts_only_agent_is_refused_on_a_published_post_and_a_publish_agent_is_allowed(): void {
		$tag = $this->term( 'post_tag', 'Live' );

		$this->act_as_agent();
		$live = $this->admin_post();
		$this->assertSame(
			self::REFUSAL,
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $live,
						'taxonomy' => 'post_tag',
						'terms'    => array( $tag ),
					)
				)
			)
		);
		$this->assert_unchanged( $live, 'post_tag', array() );

		$this->act_as_agent(
			array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::PUBLISH      => true,
			)
		);
		$draft = $this->draft();
		$this->assertSame(
			self::REFUSAL,
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $draft,
						'taxonomy' => 'post_tag',
						'terms'    => array( $tag ),
					)
				)
			),
			'A publish-only agent may not edit a draft.'
		);

		$this->act_as_agent(
			array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::PUBLISH      => true,
			)
		);
		$allowed = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $live,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);
		$this->assertTrue( $allowed['changed'] );
		$this->assertSame( array( $tag ), $this->stored_ids( $live, 'post_tag' ) );
	}

	public function test_a_missing_trashed_disallowed_type_or_unreadable_post_gets_the_same_refusal_text(): void {
		register_post_type(
			self::BOOK_TYPE,
			array(
				'public'     => true,
				'taxonomies' => array( 'post_tag' ),
			)
		);
		$tag = $this->term( 'post_tag', 'Hidden' );

		$this->act_as_agent();
		$cases = array(
			'missing'    => 999999999,
			'trashed'    => $this->draft( array( 'post_status' => 'trash' ) ),
			'disallowed' => $this->draft( array( 'post_type' => self::BOOK_TYPE ) ),
			'attachment' => self::factory()->attachment->create_object(
				'x.jpg',
				0,
				array(
					'post_mime_type' => 'image/jpeg',
					'post_author'    => $this->admin_id(),
				)
			),
		);

		$texts = array();
		foreach ( $cases as $case => $id ) {
			$texts[ $case ] = $this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $id,
						'taxonomy' => 'post_tag',
						'terms'    => array( $tag ),
					)
				)
			);
		}

		$this->act_as_agent( array( Capabilities::READ => false ) );
		$texts['unreadable'] = $this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $this->draft(),
					'taxonomy' => 'post_tag',
					'terms'    => array( $tag ),
				)
			)
		);

		foreach ( $texts as $case => $text ) {
			$this->assertSame( self::REFUSAL, $text, $case );
		}
	}

	public function test_an_administrator_without_steadhand_capabilities_is_refused(): void {
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Admin' );
		wp_set_current_user( $this->admin_id() );

		// The MCP transport answers an administrator with HTTP 403 before the tool runs, so the pipeline is called directly here.
		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assert_unchanged( $post, 'post_tag', array() );
	}

	public function test_disallowed_unknown_private_and_type_limited_taxonomies_are_refused_and_name_the_allowed_ones(): void {
		register_taxonomy( self::PRIVATE, 'post', array( 'public' => false ) );
		register_post_type(
			self::BOOK_TYPE,
			array(
				'public'     => true,
				'taxonomies' => array( self::SHELF ),
			)
		);
		register_taxonomy( self::SHELF, array( self::BOOK_TYPE ), array( 'public' => true ) );
		$this->act_as_agent();
		$post = $this->draft();
		$menu = (int) wp_insert_term( 'Menu', 'nav_menu' )['term_id'];
		$term = $this->term( 'category', 'Kept' );
		$this->assign( $post, 'category', array( $term ) );

		foreach ( array( 'nav_menu', 'link_category', 'steadhand_missing', self::PRIVATE, self::SHELF ) as $taxonomy ) {
			$text = $this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $post,
						'taxonomy' => $taxonomy,
						'terms'    => array( $menu ),
					)
				)
			);

			$this->assertStringContainsString( 'taxonomies Steadhand allows', $text, $taxonomy );
			$this->assertStringContainsString( 'category', $text, $taxonomy );
			$this->assertStringContainsString( 'post_tag', $text, $taxonomy );
			$this->assert_unchanged( $post, 'category', array( $term ) );
		}
	}

	public function test_a_page_cannot_take_a_category_because_pages_do_not_use_it(): void {
		$this->act_as_agent();
		$page = $this->draft( array( 'post_type' => 'page' ) );
		$cat  = $this->term( 'category', 'Blog only' );

		$text = $this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $page,
					'taxonomy' => 'category',
					'terms'    => array( $cat ),
				)
			)
		);

		$this->assertStringContainsString( 'taxonomies Steadhand allows for this post: none.', $text );
		$this->assert_unchanged( $page, 'category', array() );
	}

	public function test_a_term_of_another_taxonomy_or_a_missing_id_is_refused_and_the_text_names_no_other_taxonomy(): void {
		$this->act_as_agent();
		$post     = $this->draft();
		$category = $this->term( 'category', 'Other' );

		foreach ( array( $category, 999999999 ) as $id ) {
			$text = $this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $post,
						'taxonomy' => 'post_tag',
						'terms'    => array( $id ),
					)
				)
			);

			$this->assertStringContainsString( 'not a term of the taxonomy post_tag', $text );
			$this->assertStringNotContainsString( 'category', $text );
			$this->assert_unchanged( $post, 'post_tag', array() );
		}
	}

	public function test_a_hundred_and_one_ids_are_refused_by_the_schema_and_nothing_changes(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$this->assign( $post, 'post_tag', array( $this->term( 'post_tag', 'Before' ) ) );
		$before = $this->stored_ids( $post, 'post_tag' );

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => range( 1, 101 ),
				)
			)
		);

		$this->assert_unchanged( $post, 'post_tag', $before );
	}

	public function test_repeated_ids_are_refused_and_nothing_changes(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Twice' );

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => array( $tag, $tag ),
				)
			)
		);

		$this->assert_unchanged( $post, 'post_tag', array() );
	}

	public function test_an_extra_property_is_refused_and_nothing_changes(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Extra' );

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => array( $tag ),
					'names'    => array( 'Extra' ),
				)
			)
		);

		$this->assert_unchanged( $post, 'post_tag', array() );
	}

	public function test_a_string_in_terms_is_refused_by_the_schema_and_no_term_is_created(): void {
		$this->act_as_agent();
		$post   = $this->draft();
		$before = wp_count_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			)
		);

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => array( 'New Tag' ),
				)
			)
		);
		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => array( '7' ),
				)
			)
		);

		$this->assertNull( term_exists( 'New Tag', 'post_tag' ) );
		$this->assertSame(
			$before,
			wp_count_terms(
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => false,
				)
			)
		);
		$this->assert_unchanged( $post, 'post_tag', array() );
	}

	public function test_a_post_format_takes_one_term_and_two_are_refused(): void {
		$this->act_as_agent();
		$post  = $this->draft();
		$aside = $this->term( 'post_format', 'Aside' );
		$quote = $this->term( 'post_format', 'Quote' );

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'post_format',
					'terms'    => array( $aside, $quote ),
				)
			)
		);
		$this->assert_unchanged( $post, 'post_format', array() );

		$one = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_format',
				'terms'    => array( $aside ),
			)
		);
		$this->assertSame( array( $aside ), $one['term_ids'] );

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'post_format',
					'terms'    => array( $quote ),
					'mode'     => 'add',
				)
			)
		);
		$this->assertSame( array( $aside ), $this->stored_ids( $post, 'post_format' ) );
	}

	public function test_a_taxonomy_with_a_custom_assign_capability_needs_that_capability_as_well(): void {
		register_taxonomy(
			self::SHELF,
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => self::CUSTOM_CAP ),
			)
		);
		$shelf = $this->term( self::SHELF, 'Top' );

		$this->act_as_agent();
		$post = $this->draft();
		$this->assertSame(
			self::REFUSAL,
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $post,
						'taxonomy' => self::SHELF,
						'terms'    => array( $shelf ),
					)
				)
			)
		);
		$this->assert_unchanged( $post, self::SHELF, array() );

		$this->act_as_agent( array( self::CUSTOM_CAP => true ) );
		$allowed = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => self::SHELF,
				'terms'    => array( $shelf ),
			)
		);
		$this->assertSame( array( $shelf ), $allowed['term_ids'] );
	}

	public function test_a_custom_taxonomy_with_default_capabilities_attached_to_post_is_allowed(): void {
		register_taxonomy( self::CUSTOM, 'post', array( 'public' => true ) );
		$term = $this->term( self::CUSTOM, 'Mystery' );
		$this->act_as_agent();
		$post = $this->draft();

		$output = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => self::CUSTOM,
				'terms'    => array( $term ),
			)
		);

		$this->assertSame( array( $term ), $output['term_ids'] );
	}

	public function test_an_edit_locked_post_is_blocked_by_the_guardrail_and_nothing_changes(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Locked' );
		update_post_meta( $post, '_edit_lock', time() . ':' . $this->admin_id() );

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assert_unchanged( $post, 'post_tag', array() );
	}

	public function test_the_rate_limit_applies_to_term_writes(): void {
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 1 );
		$this->act_as_agent();
		$post  = $this->draft();
		$first = $this->term( 'post_tag', 'First' );
		$later = $this->term( 'post_tag', 'Later' );

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $first ),
			)
		);
		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $later ),
			)
		);

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assert_unchanged( $post, 'post_tag', array( $first ) );
	}

	public function test_a_successful_call_writes_one_ok_row_with_the_ids_and_no_names(): void {
		$this->act_as_agent();
		$agent = get_current_user_id();
		$post  = $this->draft();
		$tag   = $this->term( 'post_tag', 'Secret Name' );
		$rows  = $this->count_rows( $agent );

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);

		$this->assertSame( $rows + 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( self::ABILITY, $row['ability'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( $post, (int) $row['object_id'] );
		$this->assertStringContainsString( (string) $tag, (string) $row['input'] );
		$this->assertStringNotContainsString( 'Secret Name', (string) $row['input'] );
	}

	public function test_a_refused_call_writes_one_blocked_row(): void {
		$this->act_as_agent();
		$agent = get_current_user_id();
		$post  = $this->draft();
		$rows  = $this->count_rows( $agent );

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => 'nav_menu',
					'terms'    => array( 1 ),
				)
			)
		);

		$this->assertSame( $rows + 1, $this->count_rows( $agent ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	public function test_log_input_accepts_any_value_and_keeps_at_most_a_hundred_ids(): void {
		$ability = new SetPostTerms();

		$kept = $ability->log_input(
			array(
				'id'       => 7,
				'taxonomy' => 'post_tag',
				'terms'    => range( 1, 150 ),
			)
		);
		$this->assertCount( 100, $kept['terms'] );

		$names = $ability->log_input(
			array(
				'id'       => 7,
				'taxonomy' => 'post_tag',
				'terms'    => array( 'Secret Name', 3, null, array( 'x' ), 4.5 ),
			)
		);
		$this->assertSame( array( 'string', 3, 'NULL', 'array', 'double' ), $names['terms'], 'A name or any other value is replaced by its type.' );
		$this->assertStringNotContainsString( 'Secret Name', (string) wp_json_encode( $names ) );

		foreach ( array(
			null,
			'terms',
			5,
			array( 'terms' => new \stdClass() ),
			array(
				'id'       => 'abc',
				'taxonomy' => array(),
				'terms'    => false,
			),
		) as $garbage ) {
			$this->assertIsArray( $ability->log_input( is_array( $garbage ) ? $garbage : array( 'terms' => $garbage ) ) );
		}
	}

	public function test_a_listener_that_changes_the_terms_during_the_write_makes_the_result_partial(): void {
		$this->act_as_agent();
		$post  = $this->draft();
		$tag   = $this->term( 'post_tag', 'Wanted' );
		$extra = $this->term( 'post_tag', 'Unwanted' );
		$busy  = false;
		add_action(
			'set_object_terms',
			static function ( int $object_id, $terms, $tt_ids, string $taxonomy ) use ( &$busy, $post, $extra ): void {
				unset( $terms, $tt_ids );
				if ( $busy || $object_id !== $post || 'post_tag' !== $taxonomy ) {
					return;
				}
				$busy = true;
				wp_set_object_terms( $post, array( $extra ), 'post_tag', true );
			},
			10,
			4
		);

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);

		$this->assertSame( 'steadhand_update_partial', $this->refusal_code( $result ) );
		$this->assertStringContainsString( 'steadhand/get-post-terms', $result->get_error_message() );
	}

	public function test_the_post_content_title_modified_date_and_revisions_are_unchanged(): void {
		$post = $this->draft(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
				'post_title'   => 'Stays',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'second version',
			)
		);
		$row       = $this->row( $post );
		$revisions = $this->revision_ids( $post );
		$tag       = $this->term( 'post_tag', 'Quiet' );
		$this->act_as_agent();

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);

		$this->assertSame( $row, $this->row( $post ) );
		$this->assertSame( $revisions, $this->revision_ids( $post ) );
	}

	public function test_outside_a_steadhand_call_the_agent_cannot_assign_terms(): void {
		$this->act_as_agent();
		$post = $this->draft();

		$this->assertFalse( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( get_taxonomy( 'post_tag' )->cap->assign_terms ) );
		$this->assertFalse( current_user_can( get_taxonomy( 'category' )->cap->assign_terms ) );
		$this->assertFalse( Pipeline::is_running() );
		$this->assertSame( array(), $this->stored_ids( $post, 'post_tag' ) );
	}

	public function test_mode_add_refuses_a_result_of_more_than_a_hundred_terms(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$this->assign( $post, 'post_tag', array_map( 'intval', self::factory()->term->create_many( 50, array( 'taxonomy' => 'post_tag' ) ) ) );
		$before = $this->stored_ids( $post, 'post_tag' );
		$more   = array_map( 'intval', self::factory()->term->create_many( 60, array( 'taxonomy' => 'post_tag' ) ) );

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => $more,
				'mode'     => 'add',
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assert_unchanged( $post, 'post_tag', $before );
	}

	public function test_a_post_with_more_than_500_terms_in_the_taxonomy_is_refused_with_409(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$many = array_map( 'intval', self::factory()->term->create_many( 501, array( 'taxonomy' => 'post_tag' ) ) );
		wp_set_object_terms( $post, $many, 'post_tag', false );
		$tag = $this->term( 'post_tag', 'Too late' );

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);

		$this->assertSame( 'steadhand_refused_too_many_terms', $this->refusal_code( $result ) );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertCount( 501, $this->stored_ids( $post, 'post_tag' ) );
	}

	public function test_the_registration_has_strict_schemas_bounds_and_defaults(): void {
		$ability = wp_get_ability( self::ABILITY );
		$input   = $ability->get_input_schema();
		$output  = $ability->get_output_schema();

		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame( array( 'id', 'taxonomy', 'terms' ), $input['required'] );
		$this->assertSame( 'replace', $input['properties']['mode']['default'] );
		$this->assertSame( array( 'replace', 'add', 'remove' ), $input['properties']['mode']['enum'] );
		$this->assertSame( 100, $input['properties']['terms']['maxItems'] );
		$this->assertTrue( $input['properties']['terms']['uniqueItems'] );
		$this->assertSame( 1, $input['properties']['terms']['items']['minimum'] );
		$this->assertSame( '^[a-z0-9_-]{1,32}$', $input['properties']['taxonomy']['pattern'] );

		$this->assertFalse( $output['additionalProperties'] );
		$this->assertSame( array_keys( $output['properties'] ), $output['required'] );

		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
		$this->assertFalse( McpAbilityExposure::is_public( $ability ) );
		$this->assertFalse( McpAbilityExposure::is_meta_public( $meta ) );
	}

	/**
	 * Each input rule refuses on its own, without the object check or the write behind it, so that no single rule depends on
	 * another layer to be enforced.
	 */
	public function test_each_input_rule_refuses_by_itself(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		$page = $this->admin_post( array( 'post_type' => 'page' ) );
		$tag  = $this->term( 'post_tag', 'Rule tag' );
		$cat  = $this->term( 'category', 'Rule category' );
		$one  = $this->term( 'post_format', 'Rule one' );
		$two  = $this->term( 'post_format', 'Rule two' );
		$menu = (int) wp_insert_term( 'Rule menu', 'nav_menu' )['term_id'];
		register_post_type( self::BOOK_TYPE, array( 'public' => true ) );
		register_taxonomy( self::PRIVATE, 'post', array( 'public' => false ) );
		$private = (int) self::factory()->term->create(
			array(
				'taxonomy' => self::PRIVATE,
				'name'     => 'Rule private',
			)
		);
		register_taxonomy( self::SHELF, array( self::BOOK_TYPE ), array( 'public' => true ) );

		$ability = new SetPostTerms();
		$check   = static fn( array $args ): mixed => $ability->validate_input( $args + array( 'mode' => 'replace' ) );

		$refused = array(
			'nav_menu'                 => array(
				'id'       => $post,
				'taxonomy' => 'nav_menu',
				'terms'    => array( $menu ),
			),
			'unregistered'             => array(
				'id'       => $post,
				'taxonomy' => 'steadhand_missing',
				'terms'    => array( $tag ),
			),
			'private taxonomy'         => array(
				'id'       => $post,
				'taxonomy' => self::PRIVATE,
				'terms'    => array( $private ),
			),
			'taxonomy of another type' => array(
				'id'       => $post,
				'taxonomy' => self::SHELF,
				'terms'    => array( $tag ),
			),
			'term of another taxonomy' => array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $cat ),
			),
			'missing term'             => array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( 999999999 ),
			),
			'repeated term'            => array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag, $tag ),
			),
			'two post formats'         => array(
				'id'       => $post,
				'taxonomy' => 'post_format',
				'terms'    => array( $one, $two ),
			),
			'category on a page'       => array(
				'id'       => $page,
				'taxonomy' => 'category',
				'terms'    => array( $cat ),
			),
			'uppercase taxonomy name'  => array(
				'id'       => $post,
				'taxonomy' => 'Category',
				'terms'    => array( $cat ),
			),
		);

		foreach ( $refused as $case => $args ) {
			$this->assertWPError( $check( $args ), "$case must be refused by validate_input." );
		}

		$this->assertTrue(
			$check(
				array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => array( $tag ),
				)
			)
		);
		$this->assertTrue(
			$check(
				array(
					'id'       => $post,
					'taxonomy' => 'post_format',
					'terms'    => array( $one ),
					'mode'     => 'add',
				)
			)
		);
		$this->assertTrue(
			$check(
				array(
					'id'       => $post,
					'taxonomy' => 'post_format',
					'terms'    => array( $one, $two ),
					'mode'     => 'remove',
				)
			),
			'Remove may leave two formats; the result is checked at execute time.'
		);
	}
}
