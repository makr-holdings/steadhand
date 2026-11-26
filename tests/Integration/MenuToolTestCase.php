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

	use MenuFixtures;

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
}
