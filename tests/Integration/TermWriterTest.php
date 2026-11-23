<?php
/**
 * The TermWriter: the only place Steadhand assigns terms. What it refuses, what it accepts, and what it never does.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Pipeline\TermWriter;

/**
 * Covers SPEC §5 and §14.1 for the term writer. Each write runs inside a real write call (PostWriteTestCase::in_write()), except
 * the one that checks the writer refuses to run outside a write.
 */
final class TermWriterTest extends PostWriteTestCase {

	private const NAVIGATION = 'steadhand_nav_test';
	private const BOOK_TYPE  = 'steadhand_book';

	/**
	 * Removes the taxonomies and post types this file registers.
	 */
	protected function tearDown(): void {
		foreach ( array( self::NAVIGATION, 'steadhand_test_pages' ) as $taxonomy ) {
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
	 * Asserts that a callback throws the given exception class.
	 *
	 * @param string  $expected Exception class.
	 * @param Closure $call     The code under test.
	 */
	private function assertThrowsClass( string $expected, Closure $call ): void {
		try {
			$call();
		} catch ( \Throwable $thrown ) {
			$this->assertInstanceOf( $expected, $thrown );
			return;
		}

		$this->fail( "Expected $expected to be thrown." );
	}

	/**
	 * The term IDs a post has in one taxonomy, read straight from the table, ascending.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return int[]
	 */
	private function term_ids_of( int $post_id, string $taxonomy ): array {
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
	 * How many terms exist in a taxonomy, counted in the table.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	private function term_count( string $taxonomy ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the count the test compares.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy )
		);
	}

	/**
	 * Runs TermWriter::set() inside a real write, and returns what it returned.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $term_ids Term IDs or anything else the test sends.
	 * @return mixed
	 */
	private function set_in_write( int $post_id, string $taxonomy, array $term_ids ): mixed {
		return $this->in_write( static fn(): mixed => TermWriter::set( $post_id, $taxonomy, $term_ids ) );
	}

	/**
	 * Refuses a call made outside a write.
	 */
	public function test_set_refuses_to_run_outside_a_write(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		$tag  = (int) self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );

		$this->assertThrowsClass( \LogicException::class, static fn() => TermWriter::set( $post, 'post_tag', array( $tag ) ) );
		$this->assertSame( array(), $this->term_ids_of( $post, 'post_tag' ) );
	}

	/**
	 * Every value that is not a positive integer, and every repeat, is refused before anything is written. A string never reaches
	 * WordPress, so no term is created, and the tag count does not move.
	 */
	public function test_a_string_a_numeric_string_a_float_zero_a_negative_or_a_repeat_is_refused_and_creates_nothing(): void {
		$this->act_as_agent();
		$post   = $this->admin_post();
		$tag    = (int) self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$before = $this->term_count( 'post_tag' );

		$bad = array(
			array( 'New Tag' ),
			array( '5' ),
			array( 5.0 ),
			array( 0 ),
			array( -1 ),
			array( true ),
			array( $tag, $tag ),
			array( $tag, 'New Tag' ),
		);

		// The exception is caught inside the write, as the write's own code must: the pipeline would turn an escaped one into an error.
		$refused = 0;
		$this->in_write(
			function () use ( $post, $bad, &$refused ): void {
				foreach ( $bad as $term_ids ) {
					try {
						TermWriter::set( $post, 'post_tag', $term_ids );
					} catch ( \InvalidArgumentException ) {
						++$refused;
					}
				}
			}
		);

		$this->assertSame( count( $bad ), $refused, 'Every bad value must be refused with an exception.' );
		$this->assertSame( $before, $this->term_count( 'post_tag' ), 'No term may be created by a refused call.' );
		$this->assertNull( term_exists( 'New Tag', 'post_tag' ), '"New Tag" must not become a tag.' );
		$this->assertSame( array(), $this->term_ids_of( $post, 'post_tag' ) );
	}

	/**
	 * A term ID that exists, but belongs to another taxonomy, is refused, and the post's terms do not change.
	 */
	public function test_a_term_of_another_taxonomy_is_refused(): void {
		$this->act_as_agent();
		$post     = $this->admin_post();
		$category = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		// A new post may already hold the default category, so the category terms are compared with what they were.
		$categories = $this->term_ids_of( $post, 'category' );

		$result = $this->set_in_write( $post, 'post_tag', array( $category ) );

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( array(), $this->term_ids_of( $post, 'post_tag' ) );
		$this->assertSame( $categories, $this->term_ids_of( $post, 'category' ) );
	}

	/**
	 * A taxonomy that Steadhand does not allow is refused, even when the post's type uses it.
	 */
	public function test_a_disallowed_taxonomy_is_refused(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		$menu = wp_insert_term( 'Main', 'nav_menu' );
		$link = wp_insert_term( 'Links', 'link_category' );

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $this->set_in_write( $post, 'nav_menu', array( (int) $menu['term_id'] ) ) ) );
		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $this->set_in_write( $post, 'link_category', array( (int) $link['term_id'] ) ) ) );
		$this->assertSame( array(), $this->term_ids_of( $post, 'nav_menu' ) );
	}

	/**
	 * A taxonomy that is registered for another post type is refused for a post of an allowed type.
	 */
	public function test_a_taxonomy_not_attached_to_the_post_type_is_refused(): void {
		register_taxonomy( self::NAVIGATION, 'page', array( 'public' => true ) );
		$this->act_as_agent();
		$post = $this->admin_post();
		$term = (int) self::factory()->term->create( array( 'taxonomy' => self::NAVIGATION ) );

		$result = $this->set_in_write( $post, self::NAVIGATION, array( $term ) );

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( array(), $this->term_ids_of( $post, self::NAVIGATION ) );
	}

	/**
	 * A trashed post and a post of a type that is not allowed are both refused, with the same text as a missing post.
	 */
	public function test_a_trashed_post_and_a_post_of_a_disallowed_type_are_refused(): void {
		register_post_type(
			self::BOOK_TYPE,
			array(
				'public'     => true,
				'taxonomies' => array( 'post_tag' ),
			)
		);
		$this->act_as_agent();
		$tag     = (int) self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$trashed = $this->admin_post( array( 'post_status' => 'trash' ) );
		$book    = $this->admin_post( array( 'post_type' => self::BOOK_TYPE ) );

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->set_in_write( $trashed, 'post_tag', array( $tag ) ) ) );
		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->set_in_write( $book, 'post_tag', array( $tag ) ) ) );
		$this->assertSame( array(), $this->term_ids_of( $book, 'post_tag' ) );
	}

	/**
	 * A post that is missing is refused, and a valid call returns the stored IDs, ascending, and writes them.
	 */
	public function test_a_missing_post_is_refused_and_a_valid_call_returns_the_stored_ids(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		$beta = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Beta',
			)
		);
		$alph = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Alpha',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->set_in_write( 999999999, 'category', array( $beta ) ) ) );

		$stored = $this->set_in_write( $post, 'category', array( $beta, $alph ) );

		$this->assertSame( array( min( $alph, $beta ), max( $alph, $beta ) ), $stored );
		$this->assertSame( array( min( $alph, $beta ), max( $alph, $beta ) ), $this->term_ids_of( $post, 'category' ) );
	}

	/**
	 * An ID that matches no term of the taxonomy is refused, and nothing is written.
	 */
	public function test_a_term_id_that_does_not_exist_is_refused(): void {
		$this->act_as_agent();
		$post = $this->admin_post();

		$result = $this->set_in_write( $post, 'post_tag', array( 999999999 ) );

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $result ) );
		$this->assertSame( array(), $this->term_ids_of( $post, 'post_tag' ) );
	}

	/**
	 * An empty list removes every term of the taxonomy from the post and returns an empty list.
	 */
	public function test_an_empty_list_removes_every_term_of_the_taxonomy(): void {
		$this->act_as_agent();
		$post = $this->admin_post();
		wp_set_post_terms( $post, array( 'One', 'Two' ), 'post_tag' );

		$stored = $this->set_in_write( $post, 'post_tag', array() );

		$this->assertSame( array(), $stored );
		$this->assertSame( array(), $this->term_ids_of( $post, 'post_tag' ) );
	}

	/**
	 * A custom taxonomy registered with the default capabilities, attached to post, is accepted.
	 */
	public function test_a_custom_taxonomy_with_default_capabilities_attached_to_post_is_accepted(): void {
		register_taxonomy( self::NAVIGATION, 'post', array( 'public' => true ) );
		$this->act_as_agent();
		$post = $this->admin_post();
		$term = (int) self::factory()->term->create( array( 'taxonomy' => self::NAVIGATION ) );

		$stored = $this->set_in_write( $post, self::NAVIGATION, array( $term ) );

		$this->assertSame( array( $term ), $stored );
	}
}
