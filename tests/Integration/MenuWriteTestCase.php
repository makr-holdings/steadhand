<?php
/**
 * Shared base for the update-menu write tests: the menu fixtures, the write helpers of PostWriteTestCase, and the theme location.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Base for the menu write tests. Fixtures are created as the current user (none, or the administrator when a test sets it), and the
 * agent is made the current user by act_as_agent() or by a test.
 */
abstract class MenuWriteTestCase extends PostWriteTestCase {

	use MenuFixtures;

	/**
	 * The name of the update-menu ability.
	 */
	protected const UPDATE = 'steadhand/update-menu';

	/**
	 * Theme location registered for these tests.
	 */
	protected const LOCATION = 'steadhand_primary';

	/**
	 * An ID no menu or item has.
	 */
	protected const MISSING_ID = 987654321;

	/**
	 * Filters and actions this test added, removed in tearDown().
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * Registers the location for each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		register_nav_menus( array( self::LOCATION => 'Steadhand primary' ) );
	}

	/**
	 * Removes what the test added, and the location.
	 */
	protected function tearDown(): void {
		foreach ( $this->added as $entry ) {
			remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		$this->added = array();

		remove_theme_mod( 'nav_menu_locations' );
		unregister_nav_menu( self::LOCATION );
		parent::tearDown();
	}

	/**
	 * Adds a filter or action that tearDown() removes. The arguments match add_filter().
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 */
	protected function on( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * The classic menu term, read fresh.
	 *
	 * @param int $menu_id Menu term ID.
	 */
	protected function menu_term( int $menu_id ): \WP_Term {
		$term = wp_get_nav_menu_object( $menu_id );
		$this->assertInstanceOf( \WP_Term::class, $term, 'The menu fixture must exist.' );

		return $term;
	}

	/**
	 * Asserts a refusal with its code, and optionally its text, which must be the text without the code suffix.
	 *
	 * @param mixed       $result  The result.
	 * @param string      $code    Expected code.
	 * @param string|null $message Expected text, or null to skip it.
	 */
	protected function assert_refused( mixed $result, string $code, ?string $message = null ): void {
		$this->assertWPError( $result, 'The call must be refused.' );
		$this->assertSame( $code, $result->get_error_code() );
		if ( null !== $message ) {
			$this->assertSame( $message, $result->get_error_message() );
		}
	}

	/**
	 * Asserts the generic refusal for an item the caller may not change, or that is not in the menu.
	 *
	 * @param mixed $result The result.
	 */
	protected function assert_generic( mixed $result ): void {
		$this->assert_refused( $result, 'steadhand_forbidden', 'Not found, or this agent may not access it.' );
	}

	/**
	 * The number of rows WordPress holds for a meta key of a post, read uncached.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 */
	protected function rows_of( int $post_id, string $key ): int {
		wp_cache_delete( $post_id, 'post_meta' );

		return count( get_post_meta( $post_id, $key, false ) );
	}

	/**
	 * The raw stored row of a post, read uncached.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function raw_row( int $post_id ): \WP_Post {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		$this->assertInstanceOf( \WP_Post::class, $post, 'The post must exist.' );

		return $post;
	}
}
