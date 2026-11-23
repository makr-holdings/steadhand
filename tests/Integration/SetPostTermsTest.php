<?php
/**
 * The steadhand/set-post-terms ability, end to end over MCP: modes, permissions, refusals, the change log and the write.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\SetPostTerms;
use Steadhand\Abilities\TermReader;
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
	private const PAGE_TAX   = 'steadhand_page_test';
	private const PAGE_EDIT  = 'steadhand_page_edit_test';
	private const ONLY_POST  = 'steadhand_post_only_test';
	private const PLAIN      = 'steadhand_plain_test';
	private const LOCKED     = 'steadhand_locked';
	private const LOCKED_TAX = 'steadhand_locked_tax_test';
	private const META_CAP   = 'steadhand_meta_cap_test';

	/**
	 * Removes the taxonomies, post types, filters and actions this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		remove_all_filters( 'wp_get_object_terms' );
		remove_all_filters( 'map_meta_cap', 20 );
		remove_all_filters( 'user_has_cap', 10 );
		remove_all_filters( 'wp_check_post_lock_window' );
		remove_all_actions( 'set_object_terms' );
		delete_option( 'wp_page_for_privacy_policy' );
		$this->use_permalinks( '' );
		foreach ( array( self::SHELF, self::CUSTOM, self::NAVIGATION, self::PRIVATE, self::PAGE_TAX, self::PAGE_EDIT, self::ONLY_POST, self::LOCKED_TAX, self::META_CAP ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		foreach ( array( self::BOOK_TYPE, self::PLAIN, self::LOCKED ) as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
		parent::tearDown();
	}

	/**
	 * Sets the permalink structure in the option and in the rewrite object, and flushes the rules, so get_permalink() follows it.
	 *
	 * @param string $structure Permalink structure, or '' for the default.
	 */
	private function use_permalinks( string $structure ): void {
		global $wp_rewrite;
		update_option( 'permalink_structure', $structure );
		$wp_rewrite->init();
		$wp_rewrite->set_permalink_structure( $structure );
		$wp_rewrite->flush_rules();
	}

	/**
	 * Runs a change once, the first time WordPress reads a post's terms after this is called. The ability's first read of
	 * the terms comes after its check and before its write, so the change lands between them.
	 *
	 * @param callable $change What to do.
	 */
	private function once_after_the_first_read( callable $change ): void {
		$fired = false;
		add_filter(
			'wp_get_object_terms',
			static function ( $terms ) use ( &$fired, $change ) {
				if ( ! $fired ) {
					$fired = true;
					$change();
				}
				return $terms;
			}
		);
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
			'This post is published, scheduled or private. Changing it needs the steadhand_publish capability, which this agent does not hold. Nothing was changed.',
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
		$this->assertSame( 'error', $this->latest_row( get_current_user_id() )['result'], 'A refusal made at execute time is logged as an error.' );
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

	/**
	 * Lets the post's current edit lock look stale to the edit-lock rule, so the write proceeds, and runs $change once, while
	 * the guardrails run, after the permission check and before the ability's own execute step.
	 *
	 * @param int      $post_id Post ID.
	 * @param callable $change  What to do.
	 */
	private function change_during_the_guardrails( int $post_id, callable $change ): void {
		update_post_meta( $post_id, '_edit_lock', ( time() - 1000 ) . ':' . $this->admin_id() );
		$fired = false;
		add_filter(
			'wp_check_post_lock_window',
			static function ( $window ) use ( &$fired, $change ) {
				if ( ! $fired ) {
					$fired = true;
					$change();
				}
				return $window;
			}
		);
	}

	/**
	 * A publish between the permission check and the write: the check runs again on the post as it now is, and refuses.
	 */
	public function test_a_publish_between_the_check_and_the_write_is_refused_and_nothing_changes(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Late' );
		$this->once_after_the_first_read(
			static function () use ( $post ): void {
				wp_update_post(
					array(
						'ID'          => $post,
						'post_status' => 'publish',
					)
				);
			}
		);

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

	/**
	 * A term added between the ability's read and its write is not lost to a full replace: the call is refused, for each mode.
	 */
	public function test_a_term_added_between_the_read_and_the_write_is_refused_and_not_lost(): void {
		$cases = array(
			'replace' => array( 'mine', 'replace' ),
			'add'     => array( 'mine', 'add' ),
			'remove'  => array( 'first', 'remove' ),
		);

		foreach ( $cases as $mode => list( $sent, $mode_name ) ) {
			remove_all_filters( 'wp_get_object_terms' );
			$this->act_as_agent();
			$post  = $this->draft();
			$first = $this->term( 'post_tag', "First $mode" );
			$extra = $this->term( 'post_tag', "Extra $mode" );
			$mine  = $this->term( 'post_tag', "Mine $mode" );
			wp_set_object_terms( $post, array( $first ), 'post_tag', false );
			$this->once_after_the_first_read(
				static fn() => wp_set_object_terms( $post, array( $extra ), 'post_tag', true )
			);

			$result = $this->execute_ability(
				self::ABILITY,
				array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => array( 'first' === $sent ? $first : $mine ),
					'mode'     => $mode_name,
				)
			);

			$this->assertSame( 'steadhand_refused_content_changed', $this->refusal_code( $result ), $mode );
			$this->assertSame( 409, $result->get_error_data()['status'] );
			$this->assertSame( array( min( $first, $extra ), max( $first, $extra ) ), $this->stored_ids( $post, 'post_tag' ), "$mode must keep the other term." );
		}
	}

	/**
	 * A post trashed after the check is refused by the execute step's own check, with the not-found refusal, and nothing changes.
	 */
	public function test_a_post_trashed_after_the_check_is_refused_by_the_execute_check(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Gone' );
		$this->change_during_the_guardrails(
			$post,
			static function () use ( $post ): void {
				wp_update_post(
					array(
						'ID'          => $post,
						'post_status' => 'trash',
					)
				);
			}
		);

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);

		$this->assertSame( 'steadhand_not_found', $this->refusal_code( $result ) );
		$this->assertSame( array(), $this->stored_ids( $post, 'post_tag' ) );
	}

	/**
	 * A term deleted after the check is refused by the execute step's own validation, with that step's wording.
	 */
	public function test_a_term_deleted_after_the_check_is_refused_by_the_execute_validation(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Vanishing' );
		$this->change_during_the_guardrails(
			$post,
			static function () use ( $tag ): void {
				wp_delete_term( $tag, 'post_tag' );
			}
		);

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $tag ),
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertStringContainsString( 'of the taxonomy post_tag', $result->get_error_message() );
		$this->assert_unchanged( $post, 'post_tag', array() );
	}

	/**
	 * A published post whose category changes moves to a new address. The output says so, and a draft does not warn.
	 */
	public function test_moving_a_published_post_to_another_category_reports_its_new_address_and_a_draft_does_not_warn(): void {
		$this->use_permalinks( '/%category%/%postname%/' );
		$alpha = $this->term( 'category', 'Alpha' );
		$bravo = $this->term( 'category', 'Bravo' );
		$live  = $this->admin_post(
			array(
				'post_title' => 'Hello',
				'post_name'  => 'hello',
			)
		);
		wp_set_post_categories( $live, array( $alpha ) );
		$draft = $this->draft(
			array(
				'post_title' => 'Hello draft',
				'post_name'  => 'hello-draft',
			)
		);
		wp_set_post_categories( $draft, array( $alpha ) );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$output = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $live,
				'taxonomy' => 'category',
				'terms'    => array( $bravo ),
			)
		);

		$this->assertStringContainsString( '/bravo/hello/', $output['link'] );
		$this->assertContains( 'This change moved the post to a new address. WordPress does not redirect the old one.', $output['warnings'] );

		$draft_output = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $draft,
				'taxonomy' => 'category',
				'terms'    => array( $bravo ),
			)
		);

		$this->assertNotContains( 'This change moved the post to a new address. WordPress does not redirect the old one.', $draft_output['warnings'] );
	}

	/**
	 * A remove may leave more than 100 terms; an add that takes the post past 100 is refused.
	 */
	public function test_a_remove_can_leave_more_than_a_hundred_terms_and_an_add_that_grows_past_a_hundred_is_refused(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$ids  = array_map( 'intval', self::factory()->term->create_many( 150, array( 'taxonomy' => 'post_tag' ) ) );
		wp_set_object_terms( $post, $ids, 'post_tag', false );
		$more = $this->term( 'post_tag', 'One more' );

		$removed = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $ids[0] ),
				'mode'     => 'remove',
			)
		);

		$this->assertTrue( $removed['changed'] );
		$this->assertCount( 149, $removed['term_ids'] );
		$this->assertCount( 149, $this->stored_ids( $post, 'post_tag' ) );

		$added = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $more ),
				'mode'     => 'add',
			)
		);

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $added ) );
		$this->assertStringContainsString( 'adds terms', $added->get_error_message() );
		$this->assertCount( 149, $this->stored_ids( $post, 'post_tag' ) );
	}

	/**
	 * The privacy policy page refuses a taxonomy the way update-post refuses that page. Another page with the same
	 * taxonomy is allowed, so the refusal is the page's own rule, not the taxonomy's.
	 */
	public function test_the_privacy_policy_page_refuses_a_taxonomy_as_update_post_does_and_another_page_is_allowed(): void {
		register_taxonomy(
			self::PAGE_EDIT,
			'page',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => 'edit_pages' ),
			)
		);
		$privacy = $this->draft( array( 'post_type' => 'page' ) );
		update_option( 'wp_page_for_privacy_policy', $privacy );
		$other = $this->draft( array( 'post_type' => 'page' ) );
		$term  = (int) self::factory()->term->create( array( 'taxonomy' => self::PAGE_EDIT ) );
		$this->act_as_agent();

		$this->assertSame(
			self::REFUSAL,
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $privacy,
						'taxonomy' => self::PAGE_EDIT,
						'terms'    => array( $term ),
					)
				)
			)
		);
		$this->assert_unchanged( $privacy, self::PAGE_EDIT, array() );

		$output = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $other,
				'taxonomy' => self::PAGE_EDIT,
				'terms'    => array( $term ),
			)
		);
		$this->assertSame( array( $term ), $output['term_ids'] );
	}

	/**
	 * A taxonomy with its default capabilities is refused on a page, on a custom-capability type and on a type without meta
	 * mapping, because the post type's edit capability is not the one the taxonomy assigns. The type without meta mapping
	 * is never allowed, even when the filter adds it (SPEC §14.1), so it is refused by the allowlist before that check.
	 */
	public function test_a_default_capability_taxonomy_is_refused_on_a_page_a_custom_capability_type_and_an_unmapped_type(): void {
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( self::BOOK_TYPE, self::PLAIN ) )
		);
		register_post_type(
			self::BOOK_TYPE,
			array(
				'public'          => true,
				'capability_type' => 'steadhand_book',
			)
		);
		register_post_type(
			self::PLAIN,
			array(
				'public'       => true,
				'map_meta_cap' => false,
			)
		);
		register_taxonomy( self::PAGE_TAX, array( 'page', self::BOOK_TYPE, self::PLAIN ), array( 'public' => true ) );
		$term = (int) self::factory()->term->create( array( 'taxonomy' => self::PAGE_TAX ) );
		$this->act_as_agent();

		foreach ( array( 'page', self::BOOK_TYPE, self::PLAIN ) as $type ) {
			$post   = $this->draft( array( 'post_type' => $type ) );
			$result = $this->tool_result(
				self::TOOL_NAME,
				array(
					'id'       => $post,
					'taxonomy' => self::PAGE_TAX,
					'terms'    => array( $term ),
				)
			);
			$this->assertTrue( $result['isError'], "$type must be refused." );
			$this->assertSame( self::REFUSAL, $result['content'][0]['text'], $type );
			$this->assert_unchanged( $post, self::PAGE_TAX, array() );
		}
	}

	/**
	 * A meta capability as a taxonomy's assign capability is refused for an agent that does not hold it.
	 */
	public function test_a_meta_capability_as_the_assign_capability_is_refused_for_an_agent_without_it(): void {
		// WordPress reports a meta capability checked without a term. The check still runs, and the call is refused.
		$this->setExpectedIncorrectUsage( 'map_meta_cap' );
		register_taxonomy(
			self::META_CAP,
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => 'assign_term' ),
			)
		);
		$term = (int) self::factory()->term->create( array( 'taxonomy' => self::META_CAP ) );
		$this->act_as_agent();
		$post = $this->draft();

		$this->assertSame(
			self::REFUSAL,
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $post,
						'taxonomy' => self::META_CAP,
						'terms'    => array( $term ),
					)
				)
			)
		);
		$this->assert_unchanged( $post, self::META_CAP, array() );
	}

	/**
	 * Both assign and edit capabilities resolve to do_not_allow: that is a refusal, not a match.
	 */
	public function test_a_do_not_allow_match_is_refused_and_is_not_permission(): void {
		$this->register_locked_type();
		$this->act_as_agent();
		$post = $this->draft( array( 'post_type' => self::LOCKED ) );
		$term = (int) self::factory()->term->create( array( 'taxonomy' => self::LOCKED_TAX ) );

		$this->assertSame(
			self::REFUSAL,
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $post,
						'taxonomy' => self::LOCKED_TAX,
						'terms'    => array( $term ),
					)
				)
			)
		);
		$this->assert_unchanged( $post, self::LOCKED_TAX, array() );

		$method = new \ReflectionMethod( SetPostTerms::class, 'may_assign' );
		$method->setAccessible( true );
		$this->assertFalse( $method->invoke( new SetPostTerms(), get_post( $post ), self::LOCKED_TAX ), 'may_assign must refuse a do_not_allow match.' );
	}

	/**
	 * Registers a post type whose edit capability is do_not_allow, and a taxonomy whose assign capability is do_not_allow.
	 */
	private function register_locked_type(): void {
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( self::LOCKED ) )
		);
		// map_meta_cap is on, so the type is allowed and the do_not_allow match is what is tested. A type registered with it
		// off is never allowed (SPEC §14.1), and would be refused before the match was reached.
		register_post_type(
			self::LOCKED,
			array(
				'public'       => true,
				'map_meta_cap' => true,
				'capabilities' => array( 'edit_posts' => 'do_not_allow' ),
			)
		);
		register_taxonomy(
			self::LOCKED_TAX,
			self::LOCKED,
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => 'do_not_allow' ),
			)
		);
	}

	/**
	 * The attached-to-type check is run by authorize_object() itself: a taxonomy the type does not use is refused even when its
	 * assign capability would match. The first taxonomy, attached to pages with the same assign capability, is allowed.
	 */
	public function test_authorize_object_refuses_a_taxonomy_the_type_does_not_use_even_when_its_assign_capability_matches(): void {
		register_taxonomy(
			self::PAGE_EDIT,
			'page',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => 'edit_pages' ),
			)
		);
		register_taxonomy(
			self::ONLY_POST,
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => 'edit_pages' ),
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );
		$page    = $this->admin_post( array( 'post_type' => 'page' ) );
		$ability = new SetPostTerms();

		$results = $this->in_write(
			static fn(): array => array(
				$ability->authorize_object(
					array(
						'id'       => $page,
						'taxonomy' => self::PAGE_EDIT,
						'terms'    => array(),
					)
				),
				$ability->authorize_object(
					array(
						'id'       => $page,
						'taxonomy' => self::ONLY_POST,
						'terms'    => array(),
					)
				),
			)
		);

		$this->assertSame( array( true, false ), $results );
	}

	/**
	 * The write-error decision: a refusal passes through as it was; WordPress's own failure is update_failed when the stored set is
	 * unchanged and partial when it changed or cannot be read.
	 */
	public function test_the_write_error_decision_passes_refusals_through_and_chooses_update_failed_or_partial(): void {
		$refusal = new \WP_Error( 'steadhand_refused_content_changed', 'changed' );
		$core    = new \WP_Error( 'steadhand_update_failed', 'failed' );

		$this->assertSame( $refusal, SetPostTerms::decide_write_error( array( 1 ), array( 1 ), $refusal ) );
		$this->assertSame( $refusal, SetPostTerms::decide_write_error( array( 1 ), array( 1, 2 ), $refusal ), 'A refusal is never partial.' );
		$this->assertSame( 'steadhand_update_failed', SetPostTerms::decide_write_error( array( 1 ), array( 1 ), $core )->get_error_code() );
		$this->assertSame( 'steadhand_update_partial', SetPostTerms::decide_write_error( array( 1 ), array( 1, 2 ), $core )->get_error_code() );
		$this->assertSame( 'steadhand_update_partial', SetPostTerms::decide_write_error( array( 1 ), null, $core )->get_error_code() );
	}

	/**
	 * A failed read-back after the write is partial, and WordPress's own error is never passed on. The probe makes the read-back
	 * fail only after the write, through the action that core fires during it.
	 */
	public function test_a_failed_read_back_after_the_write_is_partial_and_the_third_party_error_is_not_passed_on(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$tag  = $this->term( 'post_tag', 'Read back' );
		add_action(
			'set_object_terms',
			static function (): void {
				add_filter(
					'wp_get_object_terms',
					static fn() => new \WP_Error( 'probe_boom', 'probe_boom: internal detail' )
				);
			}
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
		$this->assertStringNotContainsString( 'probe_boom', $result->get_error_message() );
		$this->assertStringNotContainsString( 'probe_boom', $result->get_error_code() );
	}

	/**
	 * A capability that resolves to no capability at all is refused, never taken as a match. Both the assign and the edit
	 * capability resolve to an empty list here.
	 */
	public function test_a_capability_that_resolves_to_nothing_is_refused_and_is_not_a_match(): void {
		add_filter(
			'map_meta_cap',
			static fn( array $caps, string $cap ): array => in_array( $cap, array( 'steadhand_assign_empty', 'edit_posts' ), true ) ? array() : $caps,
			20,
			2
		);
		register_taxonomy(
			'steadhand_assign_empty_tax',
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => 'steadhand_assign_empty' ),
			)
		);
		$term = (int) self::factory()->term->create( array( 'taxonomy' => 'steadhand_assign_empty_tax' ) );
		$this->act_as_agent();
		$post = $this->draft();

		$this->assertSame(
			self::REFUSAL,
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'       => $post,
						'taxonomy' => 'steadhand_assign_empty_tax',
						'terms'    => array( $term ),
					)
				)
			)
		);
		$this->assert_unchanged( $post, 'steadhand_assign_empty_tax', array() );

		unregister_taxonomy( 'steadhand_assign_empty_tax' );
	}

	/**
	 * The assign capability is checked again on the post right before the write. A capability withdrawn between the first read
	 * and the write is refused, and nothing is written.
	 */
	public function test_an_assign_capability_withdrawn_between_the_read_and_the_write_is_refused(): void {
		register_taxonomy(
			self::SHELF,
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => self::CUSTOM_CAP ),
			)
		);
		$this->act_as_agent( array( self::CUSTOM_CAP => true ) );
		$post      = $this->draft();
		$shelf     = $this->term( self::SHELF, 'Withdrawn' );
		$withdrawn = false;
		add_filter(
			'user_has_cap',
			static function ( array $allcaps ) use ( &$withdrawn ): array {
				if ( $withdrawn ) {
					unset( $allcaps[ self::CUSTOM_CAP ] );
				}
				return $allcaps;
			},
			10,
			1
		);
		$this->once_after_the_first_read(
			static function () use ( &$withdrawn ): void {
				$withdrawn = true;
			}
		);

		$result = $this->execute_ability(
			self::ABILITY,
			array(
				'id'       => $post,
				'taxonomy' => self::SHELF,
				'terms'    => array( $shelf ),
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assert_unchanged( $post, self::SHELF, array() );
	}

	/**
	 * The stored IDs are read from the database. A relationship written directly, after a read has warmed the cache, is seen.
	 */
	public function test_assigned_ids_reads_the_database_and_not_a_cache_warmed_before_a_direct_write(): void {
		$post = $this->draft();
		$a    = $this->term( 'post_tag', 'Cached A' );
		$b    = $this->term( 'post_tag', 'Cached B' );
		wp_set_object_terms( $post, array( $a ), 'post_tag', false );
		$this->assertSame( array( $a ), TermReader::assigned_ids( $post, 'post_tag' ) );

		global $wpdb;
		$tt = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Finds the taxonomy row for the test.
			$wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $b, 'post_tag' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name.
		);
		// A direct insert: no cache is cleared, so a stale cache would still show only $a.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The row a stale cache would miss.
		$wpdb->insert(
			$wpdb->term_relationships,
			array(
				'object_id'        => $post,
				'term_taxonomy_id' => $tt,
				'term_order'       => 0,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The row a stale cache would miss.

		$this->assertSame( array( min( $a, $b ), max( $a, $b ) ), TermReader::assigned_ids( $post, 'post_tag' ) );
	}

	/**
	 * Adding terms already on a post that holds more than 100 is no growth, so it is allowed and changes nothing.
	 */
	public function test_an_add_of_terms_already_on_a_post_with_more_than_a_hundred_is_allowed_and_changes_nothing(): void {
		$this->act_as_agent();
		$post = $this->draft();
		$ids  = array_map( 'intval', self::factory()->term->create_many( 150, array( 'taxonomy' => 'post_tag' ) ) );
		wp_set_object_terms( $post, $ids, 'post_tag', false );

		$output = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $ids[0], $ids[1] ),
				'mode'     => 'add',
			)
		);

		$this->assertFalse( $output['changed'] );
		$this->assertCount( 150, $output['term_ids'] );
	}
}
