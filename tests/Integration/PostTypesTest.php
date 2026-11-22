<?php
/**
 * The steadhand_allowed_post_types filter: default list, fixed exclusions, and safe handling of bad values.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\PostTypes;

/**
 * Covers SPEC §5 and §14.1, and the extension point documented in docs/HOOKS.md.
 */
final class PostTypesTest extends TestCase {

	private const FILTER = 'steadhand_allowed_post_types';
	private const CUSTOM = 'steadhand_book';

	/**
	 * Removes the filters and the custom type this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( self::FILTER );
		if ( post_type_exists( self::CUSTOM ) ) {
			unregister_post_type( self::CUSTOM );
		}
		parent::tearDown();
	}

	/**
	 * Makes the filter return the given value.
	 *
	 * @param mixed $value Value the filter returns.
	 */
	private function filter_returns( mixed $value ): void {
		add_filter(
			self::FILTER,
			static fn(): mixed => $value
		);
	}

	public function test_default_is_post_and_page(): void {
		$this->assertSame( array( 'post', 'page' ), PostTypes::allowed() );
		$this->assertTrue( PostTypes::is_allowed( 'post' ) );
		$this->assertTrue( PostTypes::is_allowed( 'page' ) );
		$this->assertFalse( PostTypes::is_allowed( 'steadhand_book' ) );
	}

	public function test_the_always_excluded_list_is_the_spec_list(): void {
		$this->assertSame(
			array( 'attachment', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block', 'wp_global_styles', 'nav_menu_item', 'revision' ),
			PostTypes::ALWAYS_EXCLUDED
		);
	}

	public function test_a_filter_adding_a_registered_custom_type_is_honoured(): void {
		register_post_type( self::CUSTOM, array( 'public' => true ) );
		add_filter(
			self::FILTER,
			static fn( array $types ): array => array_merge( $types, array( self::CUSTOM ) )
		);

		$this->assertSame( array( 'post', 'page', self::CUSTOM ), PostTypes::allowed() );
		$this->assertTrue( PostTypes::is_allowed( self::CUSTOM ) );
	}

	public function test_the_filter_replaces_the_default_list(): void {
		register_post_type( self::CUSTOM, array( 'public' => true ) );
		$this->filter_returns( array( self::CUSTOM ) );

		$this->assertSame( array( self::CUSTOM ), PostTypes::allowed() );
		$this->assertFalse( PostTypes::is_allowed( 'post' ) );
	}

	/**
	 * One case per always-excluded post type.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function always_excluded_provider(): array {
		$cases = array();
		foreach ( PostTypes::ALWAYS_EXCLUDED as $type ) {
			$cases[ $type ] = array( $type );
		}

		return $cases;
	}

	/**
	 * An always-excluded type has no effect when the filter adds it.
	 *
	 * @dataProvider always_excluded_provider
	 * @param string $type Always-excluded post type.
	 */
	public function test_an_always_excluded_type_has_no_effect_when_the_filter_adds_it( string $type ): void {
		$this->filter_returns( array( 'post', 'page', $type, $type ) );

		$this->assertSame( array( 'post', 'page' ), PostTypes::allowed() );
		$this->assertFalse( PostTypes::is_allowed( $type ) );
	}

	public function test_an_always_excluded_type_is_removed_even_when_it_is_the_only_value(): void {
		$this->filter_returns( array( 'attachment' ) );

		$this->assertSame( array(), PostTypes::allowed() );
	}

	/**
	 * Non-array values the filter might return.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function non_array_provider(): array {
		return array(
			'string'  => array( 'post' ),
			'null'    => array( null ),
			'integer' => array( 42 ),
			'false'   => array( false ),
			'object'  => array( new \stdClass() ),
		);
	}

	/**
	 * A non-array return from the filter falls back to the default list.
	 *
	 * @dataProvider non_array_provider
	 * @param mixed $value Value the filter returns.
	 */
	public function test_a_non_array_return_falls_back_to_the_default( mixed $value ): void {
		$this->filter_returns( $value );

		$this->assertSame( array( 'post', 'page' ), PostTypes::allowed() );
	}

	public function test_non_string_unregistered_and_odd_values_are_dropped_without_warnings(): void {
		register_post_type( self::CUSTOM, array( 'public' => true ) );
		$this->filter_returns(
			array(
				'page',
				7,
				null,
				true,
				array( 'post' ),
				new \stdClass(),
				'Page',              // Not a sanitize_key() fixed point (uppercase).
				'steadhand book',    // Contains a space.
				'steadhand_missing', // Not registered.
				'post',
				'book' => self::CUSTOM, // Odd key: the value is kept and the list is re-indexed.
			)
		);

		$allowed = PostTypes::allowed();

		$this->assertSame( array( 'page', 'post', self::CUSTOM ), $allowed );
		$this->assertSame( array_values( $allowed ), $allowed, 'The result must be a re-indexed list.' );
		$this->assertFalse( PostTypes::is_allowed( 'steadhand_missing' ) );
	}

	public function test_duplicates_collapse(): void {
		register_post_type( self::CUSTOM, array( 'public' => true ) );
		$this->filter_returns( array( 'post', 'post', 'page', self::CUSTOM, self::CUSTOM, 'post' ) );

		$this->assertSame( array( 'post', 'page', self::CUSTOM ), PostTypes::allowed() );
	}

	public function test_is_allowed_is_false_for_an_unknown_string(): void {
		$this->assertFalse( PostTypes::is_allowed( '' ) );
		$this->assertFalse( PostTypes::is_allowed( 'attachment' ) );
	}
	/**
	 * One case per built-in post type other than post and page, as WordPress reports them in this install.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function builtin_type_provider(): array {
		$cases = array();
		foreach ( array_keys( get_post_types( array( '_builtin' => true ) ) ) as $type ) {
			if ( ! in_array( $type, array( 'post', 'page' ), true ) ) {
				$cases[ $type ] = array( $type );
			}
		}

		return $cases;
	}

	/**
	 * SPEC §14.1: every built-in type other than post and page is excluded, whatever the filter returns, so a
	 * future core type is excluded without a list change.
	 *
	 * @dataProvider builtin_type_provider
	 * @param string $type Built-in post type.
	 */
	public function test_a_builtin_type_is_excluded_even_when_the_filter_adds_it( string $type ): void {
		$this->filter_returns( array( 'post', 'page', $type ) );

		$this->assertSame( array( 'post', 'page' ), PostTypes::allowed() );
		$this->assertFalse( PostTypes::is_allowed( $type ) );
	}

	public function test_a_filter_that_returns_every_registered_type_leaves_only_post_and_page_of_the_builtin_types(): void {
		$this->filter_returns( array_keys( get_post_types() ) );

		$allowed = PostTypes::allowed();
		$builtin = array_keys( get_post_types( array( '_builtin' => true ) ) );

		$this->assertSame( array( 'post', 'page' ), array_values( array_intersect( $allowed, $builtin ) ) );
		foreach ( array( 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_font_family', 'wp_font_face' ) as $type ) {
			if ( post_type_exists( $type ) ) {
				$this->assertNotContains( $type, $allowed, "$type is built in and must be excluded." );
			}
		}
	}

	public function test_a_custom_type_that_is_not_built_in_can_still_be_added(): void {
		register_post_type( self::CUSTOM, array( 'public' => true ) );
		$this->filter_returns( array( 'post', 'page', self::CUSTOM ) );

		$this->assertSame( array( 'post', 'page', self::CUSTOM ), PostTypes::allowed() );
	}
}
