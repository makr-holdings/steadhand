<?php
/**
 * Shared fixtures for the menu tools' tests: classic menus, their items and their theme location, and block navigation.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Base for the menu tests. The fixtures are created as the fixture administrator.
 */
abstract class MenuToolTestCase extends ReadToolTestCase {

	/**
	 * Theme location registered for these tests.
	 */
	protected const LOCATION = 'steadhand_primary';

	/**
	 * Registers the location for each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		register_nav_menus( array( self::LOCATION => 'Steadhand primary' ) );
	}

	/**
	 * Removes the location and its assignment.
	 */
	protected function tearDown(): void {
		remove_theme_mod( 'nav_menu_locations' );
		unregister_nav_menu( self::LOCATION );
		parent::tearDown();
	}

	/**
	 * Creates a classic menu and returns its term ID.
	 *
	 * @param string $name Menu name, unique within the test.
	 */
	protected function classic_menu( string $name ): int {
		$id = wp_create_nav_menu( $name );
		$this->assertIsInt( $id, 'The menu fixture must be created: ' . $name );

		return $id;
	}

	/**
	 * Adds a custom-link item to a classic menu and returns the item's post ID.
	 *
	 * @param int    $menu     Menu term ID.
	 * @param string $title    Item title.
	 * @param string $url      Item URL.
	 * @param int    $parent_item Parent item ID, or 0.
	 * @param int    $position Position in the menu.
	 */
	protected function custom_item( int $menu, string $title, string $url, int $parent_item = 0, int $position = 0 ): int {
		$item = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'custom',
				'menu-item-title'     => $title,
				'menu-item-url'       => $url,
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $parent_item,
				'menu-item-position'  => $position,
			)
		);
		$this->assertIsInt( $item, 'The item fixture must be created: ' . $title );

		return $item;
	}

	/**
	 * Adds a draft custom-link item to a classic menu (a hidden item) and returns the item's post ID.
	 *
	 * @param int    $menu        Menu term ID.
	 * @param string $title       Item title.
	 * @param string $url         Item URL.
	 * @param int    $parent_item Parent item ID, or 0.
	 * @param int    $position    Position in the menu.
	 */
	protected function draft_custom_item( int $menu, string $title, string $url, int $parent_item = 0, int $position = 0 ): int {
		$item = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'custom',
				'menu-item-title'     => $title,
				'menu-item-url'       => $url,
				'menu-item-status'    => 'draft',
				'menu-item-parent-id' => $parent_item,
				'menu-item-position'  => $position,
			)
		);
		$this->assertIsInt( $item, 'The draft item fixture must be created: ' . $title );

		return $item;
	}

	/**
	 * Assigns a classic menu to the test's theme location.
	 *
	 * @param int $menu Menu term ID.
	 */
	protected function assign_location( int $menu ): void {
		set_theme_mod( 'nav_menu_locations', array( self::LOCATION => $menu ) );
	}

	/**
	 * Creates a wp_navigation post in the given status and returns its ID.
	 *
	 * @param string $status  Post status.
	 * @param string $content Raw post content.
	 * @param string $title   Post title.
	 */
	protected function block_navigation( string $status, string $content = '', string $title = 'Site navigation' ): int {
		$id = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $content,
				'post_author'  => $this->admin_id(),
			)
		);
		$this->assertGreaterThan( 0, $id, 'The navigation fixture must be created.' );

		return (int) $id;
	}
}
