<?php
/**
 * Tests for MenuWriter::became_published(), the listener that clears the hidden keys when a menu item is published. Each Save Menu
 * publishes every item, so the listener must cost no delete for an item that has no keys, and no query at all once the meta is cached.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\MenuRules;
use Steadhand\Pipeline\MenuWriter;

/**
 * Calls the listener directly, as the publish transition does.
 */
final class MenuPublishListenerTest extends MenuWriteTestCase {

	/**
	 * The SQL statements that ran while a query filter was attached.
	 *
	 * @var string[]
	 */
	private array $queries = array();

	/**
	 * Publishing menu items that carry no hidden keys runs no delete, and once their meta is cached, runs no query at all.
	 */
	public function test_publishing_items_without_hidden_keys_runs_no_delete_and_then_no_query(): void {
		$menu  = $this->classic_menu( 'Publish listener ' . wp_generate_password( 6, false ) );
		$items = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$items[] = $this->custom_item( $menu, "Item $i", "https://example.com/item-$i" );
		}

		$posts = array();
		foreach ( $items as $item ) {
			$posts[] = $this->raw_row( $item );
		}

		$this->on(
			'query',
			function ( string $sql ): string {
				$this->queries[] = $sql;

				return $sql;
			},
			10,
			1
		);

		foreach ( $posts as $post ) {
			MenuWriter::became_published( 'publish', 'draft', $post );
		}
		$deletes = array_filter( $this->queries, static fn( string $sql ): bool => 1 === preg_match( '/^\s*DELETE\b/i', $sql ) );
		$this->assertSame( array(), array_values( $deletes ), 'An item with no hidden keys must cost no delete.' );
		// At most one read per item to prime its meta cache. The old code ran three delete_post_meta() calls, each with its own read, per item.
		$this->assertLessThanOrEqual( count( $posts ), count( $this->queries ), 'The first pass may read each item once, and no more.' );

		$this->queries = array();
		foreach ( $posts as $post ) {
			MenuWriter::became_published( 'publish', 'draft', $post );
		}
		$this->assertSame( array(), $this->queries, 'Once the meta is cached, publishing the same items must run no query.' );
	}

	/**
	 * An item that does carry the hidden keys still has them removed when it is published.
	 */
	public function test_publishing_an_item_that_has_hidden_keys_removes_them(): void {
		$menu = $this->classic_menu( 'Publish keys ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Keyed', 'https://example.com/keyed' );
		update_post_meta( $item, MenuRules::HIDDEN_META, '1' );
		update_post_meta( $item, MenuRules::HIDDEN_ITEM_META, (string) $item );

		MenuWriter::became_published( 'publish', 'draft', $this->raw_row( $item ) );

		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $item, $key ), "Publishing must remove $key." );
		}
	}
}
