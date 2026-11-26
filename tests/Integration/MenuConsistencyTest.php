<?php
/**
 * List-menus and get-menu must agree on which block navigation they show, and classic menu item titles are plain text.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Each status is checked in both tools. A status that list-menus shows must be readable by get-menu, and a status
 * that get-menu refuses must not be listed.
 */
final class MenuConsistencyTest extends MenuToolTestCase {

	private const LIST_TOOL = 'steadhand-list-menus';
	private const GET_TOOL  = 'steadhand-get-menu';

	/**
	 * Refusal text the pipeline gives for every object-level miss.
	 */
	private const NOT_FOUND = 'Not found, or this agent may not access it.';

	/**
	 * Block navigation statuses both tools show.
	 */
	private const SHOWN = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * Block navigation statuses both tools refuse.
	 */
	private const HIDDEN = array( 'trash', 'auto-draft' );

	/**
	 * Creates block navigation in a status and returns its ID. A future post needs a date in the future to keep that
	 * status.
	 *
	 * @param string $status Post status.
	 */
	private function navigation( string $status ): int {
		$fields = array();
		if ( 'future' === $status ) {
			$fields = array(
				'post_date'     => '2099-01-01 00:00:00',
				'post_date_gmt' => '2099-01-01 00:00:00',
			);
		}

		$id = wp_insert_post(
			array_merge(
				array(
					'post_type'    => 'wp_navigation',
					'post_status'  => $status,
					'post_title'   => 'Nav ' . $status,
					'post_content' => '',
					'post_author'  => $this->admin_id(),
				),
				$fields
			)
		);
		$this->assertGreaterThan( 0, $id, "The $status navigation fixture must be created." );

		return (int) $id;
	}

	public function test_every_status_list_menus_shows_is_readable_by_get_menu_and_listed(): void {
		$this->act_as_agent();
		$ids = array();
		foreach ( self::SHOWN as $status ) {
			$ids[ $status ] = $this->navigation( $status );
		}

		$listed = array_column( $this->output( self::LIST_TOOL )['menus'], 'status', 'id' );

		foreach ( $ids as $status => $id ) {
			$this->assertArrayHasKey( $id, $listed, "list-menus must list $status block navigation." );
			$this->assertSame( $status, $listed[ $id ] );
			$menu = $this->output(
				self::GET_TOOL,
				array(
					'kind' => 'block',
					'id'   => $id,
				)
			);
			$this->assertSame( $id, $menu['id'], "get-menu must read $status block navigation." );
		}
	}

	public function test_every_status_that_get_menu_refuses_is_not_listed(): void {
		$this->act_as_agent();
		$ids = array();
		foreach ( self::HIDDEN as $status ) {
			$ids[ $status ] = $this->navigation( $status );
		}

		$listed = array_column( $this->output( self::LIST_TOOL )['menus'], 'id' );

		foreach ( $ids as $status => $id ) {
			$this->assertNotContains( $id, $listed, "list-menus must not list $status block navigation." );
			$this->assertSame(
				self::NOT_FOUND,
				$this->refusal_text(
					$this->tool_result(
						self::GET_TOOL,
						array(
							'kind' => 'block',
							'id'   => $id,
						)
					)
				),
				"get-menu must refuse $status block navigation."
			);
		}
	}

	public function test_a_classic_item_title_is_plain_text_with_tags_removed_entities_decoded_and_cut(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand titles' );
		$item = $this->custom_item( $menu, '<b>Shop</b> &amp; <i>more</i>', 'https://example.test/shop', 0, 1 );
		$long = $this->custom_item( $menu, str_repeat( "\u{00e9}", 1500 ), 'https://example.test/long', 0, 2 );

		$items = array_column(
			$this->output(
				'steadhand-get-menu',
				array(
					'kind' => 'classic',
					'id'   => $menu,
				)
			)['items'],
			null,
			'id'
		);

		$this->assertSame( 'Shop & more', $items[ $item ]['title'] );
		$this->assertLessThanOrEqual( 1000, strlen( $items[ $long ]['title'] ) );
		$this->assertSame( 1, preg_match( '//u', $items[ $long ]['title'] ), 'The cut title must stay valid UTF-8.' );
	}

	/**
	 * A classic menu whose only item is hidden is listed by list-menus, with an item_count of 0, and get-menu returns its one item,
	 * hidden. The two tools agree on the menu; they differ only in the count, which is the stored count of published items.
	 */
	public function test_a_classic_menu_with_only_a_hidden_item_is_listed_and_its_item_is_readable(): void {
		$this->act_as_agent();
		$menu = $this->classic_menu( 'Steadhand only hidden' );
		$item = $this->draft_custom_item( $menu, 'Only hidden', 'https://example.test/only-hidden/', 0, 1 );

		$listed = array_column( $this->output( self::LIST_TOOL )['menus'], null, 'id' );
		$this->assertArrayHasKey( $menu, $listed, 'list-menus lists the classic menu.' );
		$this->assertSame( 0, $listed[ $menu ]['item_count'] );

		$menu_output = $this->output(
			self::GET_TOOL,
			array(
				'kind' => 'classic',
				'id'   => $menu,
			)
		);
		$this->assertSame( array( $item ), array_column( $menu_output['items'], 'id' ), 'get-menu returns the hidden item.' );
		$this->assertTrue( $menu_output['items'][0]['hidden'] );
	}
}
