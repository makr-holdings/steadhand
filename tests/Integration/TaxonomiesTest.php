<?php
/**
 * Which taxonomies agents may read: the allowlist rule in Steadhand\Taxonomies.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\PostTypes;
use Steadhand\Taxonomies;

/**
 * Covers SPEC §5 (terms) and §14.1 (taxonomies follow from the allowed post types).
 */
final class TaxonomiesTest extends TestCase {

	private const FILTER  = 'steadhand_allowed_post_types';
	private const TYPE    = 'steadhand_book';
	private const PUBLIC  = 'steadhand_genre';
	private const PRIVATE = 'steadhand_shelf';

	/**
	 * Removes the filters, types and taxonomies this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( self::FILTER );
		foreach ( array( self::PUBLIC, self::PRIVATE ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		if ( post_type_exists( self::TYPE ) ) {
			unregister_post_type( self::TYPE );
		}
		parent::tearDown();
	}

	/**
	 * Every name in Taxonomies::ALWAYS_EXCLUDED, one case each.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function always_excluded_provider(): array {
		$cases = array();
		foreach ( Taxonomies::ALWAYS_EXCLUDED as $taxonomy ) {
			$cases[ $taxonomy ] = array( $taxonomy );
		}

		return $cases;
	}

	public function test_the_fixed_exclusion_list_is_the_documented_list(): void {
		$this->assertSame(
			array( 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ),
			Taxonomies::ALWAYS_EXCLUDED
		);
	}

	public function test_the_defaults_include_category_and_post_tag(): void {
		$allowed = Taxonomies::allowed();

		$this->assertContains( 'category', $allowed );
		$this->assertContains( 'post_tag', $allowed );
		$this->assertTrue( Taxonomies::is_allowed( 'category' ) );
		$this->assertTrue( Taxonomies::is_allowed( 'post_tag' ) );
	}

	public function test_nav_menu_is_never_allowed(): void {
		$this->assertNotContains( 'nav_menu', Taxonomies::allowed() );
		$this->assertFalse( Taxonomies::is_allowed( 'nav_menu' ) );
	}

	public function test_a_name_that_is_not_registered_is_not_allowed(): void {
		$this->assertFalse( Taxonomies::is_allowed( 'steadhand_missing' ) );
		$this->assertFalse( Taxonomies::is_allowed( '' ) );
	}

	/**
	 * Each always-excluded name is refused even when it is public and attached to an allowed type. The test forces
	 * those properties on, so only the fixed list can refuse the name.
	 *
	 * @dataProvider always_excluded_provider
	 * @param string $taxonomy Taxonomy name from ALWAYS_EXCLUDED.
	 */
	public function test_an_always_excluded_taxonomy_is_refused_even_when_public_and_attached_to_post( string $taxonomy ): void {
		global $wp_taxonomies;

		$original = $wp_taxonomies[ $taxonomy ] ?? null;
		if ( null === $original ) {
			register_taxonomy( $taxonomy, 'post', array( 'public' => true ) );
		} else {
			$forced                     = clone $original;
			$forced->public             = true;
			$forced->object_type        = array( 'post' );
			$wp_taxonomies[ $taxonomy ] = $forced; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core has no API to change a registered taxonomy's public flag, which the test needs.
		}

		try {
			$this->assertNotContains( $taxonomy, Taxonomies::allowed() );
			$this->assertFalse( Taxonomies::is_allowed( $taxonomy ) );
		} finally {
			if ( null === $original ) {
				unregister_taxonomy( $taxonomy );
			} else {
				$wp_taxonomies[ $taxonomy ] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the taxonomy changed above.
			}
		}
	}

	public function test_a_public_custom_taxonomy_on_post_is_allowed(): void {
		register_taxonomy( self::PUBLIC, 'post', array( 'public' => true ) );

		$this->assertContains( self::PUBLIC, Taxonomies::allowed() );
		$this->assertTrue( Taxonomies::is_allowed( self::PUBLIC ) );
	}

	public function test_a_custom_taxonomy_that_is_not_public_on_post_is_not_allowed(): void {
		register_taxonomy( self::PRIVATE, 'post', array( 'public' => false ) );

		$this->assertNotContains( self::PRIVATE, Taxonomies::allowed() );
		$this->assertFalse( Taxonomies::is_allowed( self::PRIVATE ) );
	}

	public function test_a_public_taxonomy_on_a_type_that_is_not_allowed_is_not_allowed_until_the_type_is_added(): void {
		register_post_type( self::TYPE, array( 'public' => true ) );
		register_taxonomy( self::PUBLIC, self::TYPE, array( 'public' => true ) );

		$this->assertFalse( PostTypes::is_allowed( self::TYPE ) );
		$this->assertNotContains( self::PUBLIC, Taxonomies::allowed() );

		add_filter(
			self::FILTER,
			static fn( array $types ): array => array_merge( $types, array( self::TYPE ) )
		);

		$this->assertContains( self::PUBLIC, Taxonomies::allowed() );
		$this->assertTrue( Taxonomies::is_allowed( self::PUBLIC ) );
	}

	public function test_allowed_is_a_re_indexed_list_of_strings(): void {
		$allowed = Taxonomies::allowed();

		$this->assertSame( array_values( $allowed ), $allowed );
		foreach ( $allowed as $taxonomy ) {
			$this->assertIsString( $taxonomy );
		}
	}
}
