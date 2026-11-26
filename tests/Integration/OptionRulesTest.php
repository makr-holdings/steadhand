<?php
/**
 * Tests for OptionRules: the steadhand_allowed_options filter and what it cannot do, the hard-coded blocks, the value rule at its
 * limits, and the stored-value rule. Most tests are negative.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\OptionRules;

/**
 * Tests for OptionRules: the steadhand_allowed_options filter and what it cannot do, the hard-coded blocks, the value rule at its
 * limits, and the stored-value rule. Most tests are negative.
 */
final class OptionRulesTest extends OptionTestCase {

	/**
	 * A plain-text option that the filter may add. It must not be hard-blocked, and it is never created by these tests.
	 */
	private const EXTRA = 'probe_option';

	/**
	 * With no filter, the allowlist is exactly the two built-in options and their limits.
	 */
	public function test_the_default_allowlist_is_the_two_built_in_options(): void {
		$this->assertSame(
			array(
				'blogname'        => array( 'max_length' => 200 ),
				'blogdescription' => array( 'max_length' => 500 ),
			),
			OptionRules::allowed()
		);
	}

	/**
	 * A filter that returns something that is not an array leaves the built-in list, and so does a filter that throws.
	 */
	public function test_a_filter_that_returns_no_array_or_throws_leaves_the_defaults(): void {
		$this->on( 'steadhand_allowed_options', static fn(): string => 'not a list' );
		$this->assertSame( array( 'blogname', 'blogdescription' ), array_keys( OptionRules::allowed() ) );

		remove_all_filters( 'steadhand_allowed_options' );
		$this->on(
			'steadhand_allowed_options',
			static function (): never {
				throw new \RuntimeException( 'A filter that fails.' );
			}
		);
		$this->assertSame( array( 'blogname', 'blogdescription' ), array_keys( OptionRules::allowed() ) );
	}

	/**
	 * The filter cannot add a hard-blocked name, in any letter case, and cannot bring back a name the hard block removes.
	 *
	 * @dataProvider hard_blocked_additions
	 *
	 * @param string $name The name the filter tries to add.
	 */
	public function test_the_filter_cannot_add_a_hard_blocked_name( string $name ): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ) use ( $name ): array {
				$options[ $name ] = array( 'max_length' => 100 );

				return $options;
			}
		);

		$this->assertArrayNotHasKey( $name, OptionRules::allowed() );
		$this->assertTrue( OptionRules::hard_blocked( $name ) );
	}

	/**
	 * Names the filter may not add.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function hard_blocked_additions(): array {
		return array(
			'siteurl'            => array( 'siteurl' ),
			'upper case home'    => array( 'HOME' ),
			'active plugins'     => array( 'active_plugins' ),
			'a widget'           => array( 'widget_text' ),
			'a theme mod'        => array( 'theme_mods_twentytwentyfive' ),
			'a steadhand option' => array( 'steadhand_db_version' ),
			'a transient'        => array( '_transient_x' ),
			'a site transient'   => array( '_site_transient_x' ),
			'the roles option'   => array( 'wp_user_roles' ),
			'a capabilities row' => array( 'wp_capabilities' ),
			'user level'         => array( 'wp_user_level' ),
			'the password salt'  => array( 'auth_salt' ),
		);
	}

	/**
	 * The filter cannot add a name that is not well formed, an entry whose definition is bad, or a non-string key.
	 *
	 * @dataProvider malformed_additions
	 *
	 * @param string|int $name       The key the filter gives.
	 * @param mixed      $definition The definition the filter gives.
	 */
	public function test_the_filter_cannot_add_a_malformed_entry( string|int $name, mixed $definition ): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ) use ( $name, $definition ): array {
				$options[ $name ] = $definition;

				return $options;
			}
		);

		$this->assertSame( array( 'blogname', 'blogdescription' ), array_keys( OptionRules::allowed() ) );
	}

	/**
	 * Malformed additions: a bad name, a missing or non-integer or out-of-range limit, and a definition that is not an array.
	 *
	 * @return array<string, array{0: string|int, 1: mixed}>
	 */
	public static function malformed_additions(): array {
		return array(
			'a name with a space'               => array( 'bad name', array( 'max_length' => 10 ) ),
			'a name with a dot'                 => array( 'bad.name', array( 'max_length' => 10 ) ),
			'a name over 64 characters'         => array( str_repeat( 'a', 65 ), array( 'max_length' => 10 ) ),
			'an integer key'                    => array( 7, array( 'max_length' => 10 ) ),
			'no max_length'                     => array( self::EXTRA, array( 'other' => 10 ) ),
			'a string max_length'               => array( self::EXTRA, array( 'max_length' => '10' ) ),
			'a zero max_length'                 => array( self::EXTRA, array( 'max_length' => 0 ) ),
			'a max_length over 2000'            => array( self::EXTRA, array( 'max_length' => 2001 ) ),
			'a definition that is not an array' => array( self::EXTRA, 100 ),
		);
	}

	/**
	 * The filter may add a plain-text option with a limit, and may remove a built-in one.
	 */
	public function test_the_filter_can_add_a_plain_text_option_and_remove_a_built_in_one(): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				unset( $options['blogname'] );
				$options[ self::EXTRA ] = array( 'max_length' => 120 );

				return $options;
			}
		);

		$allowed = OptionRules::allowed();

		$this->assertArrayNotHasKey( 'blogname', $allowed, 'An owner may turn blogname off.' );
		$this->assertSame( array( 'max_length' => 120 ), $allowed[ self::EXTRA ] );
	}

	/**
	 * The allowlist keeps at most 50 entries, and the built-in two come first.
	 */
	public function test_the_allowlist_keeps_at_most_fifty_entries_and_the_built_in_names_first(): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				for ( $i = 0; $i < 60; ++$i ) {
					$options[ 'extra_' . $i ] = array( 'max_length' => 10 );
				}

				return $options;
			}
		);

		$names = array_keys( OptionRules::allowed() );

		$this->assertCount( OptionRules::MAX_ENTRIES, $names );
		$this->assertSame( array( 'blogname', 'blogdescription' ), array_slice( $names, 0, 2 ) );
	}

	/**
	 * A valid value passes at exactly the limit, and one more character is refused, for the title.
	 */
	public function test_the_title_limit_is_exactly_200_characters(): void {
		$this->assertNull( OptionRules::value_error( 'blogname', str_repeat( 'a', 200 ) ) );

		$refused = OptionRules::value_error( 'blogname', str_repeat( 'a', 201 ) );
		$this->assertSame( 'steadhand_invalid_input', $refused->get_error_code() );
	}

	/**
	 * An empty tagline passes; an empty title passes the value rule too, because it is a plain empty string.
	 */
	public function test_an_empty_value_passes_the_value_rule(): void {
		$this->assertNull( OptionRules::value_error( 'blogdescription', '' ) );
	}

	/**
	 * Each value the plain-text rule refuses is refused with steadhand_invalid_input: markup, an entity other than &amp;, a bare ampersand,
	 * a control character, leading and trailing and repeated spaces, a non-standard space, a percent-encoded octet, a newline, invalid
	 * UTF-8, and a value that is not a string.
	 *
	 * @dataProvider refused_by_the_value_rule
	 *
	 * @param mixed $value The value.
	 */
	public function test_the_value_rule_refuses_a_value_that_is_not_plain_text( mixed $value ): void {
		$refused = OptionRules::value_error( 'blogname', $value );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'steadhand_invalid_input', $refused->get_error_code() );
	}

	/**
	 * Values the plain-text rule refuses.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function refused_by_the_value_rule(): array {
		return array(
			'a script tag'        => array( '<script>x</script>' ),
			'a bare less-than'    => array( 'a < b' ),
			'a named entity'      => array( 'a &nbsp; b' ),
			'a numeric entity'    => array( 'a &#38; b' ),
			'a bare ampersand'    => array( 'Tom & Jerry' ),
			'a control character' => array( "a\x07b" ),
			'a newline'           => array( "a\nb" ),
			'leading space'       => array( ' a' ),
			'trailing space'      => array( 'a ' ),
			'repeated space'      => array( 'a  b' ),
			'a no-break space'    => array( "a\u{00A0}b" ),
			'a zero-width space'  => array( "a\u{200B}b" ),
			'percent-encoding'    => array( 'a%20b' ),
			'invalid utf-8'       => array( "bad\xC3(" ),
			'an integer'          => array( 42 ),
			'an array'            => array( array( 'a' ) ),
		);
	}

	/**
	 * An ampersand written as &amp; is plain text, and passes.
	 */
	public function test_an_ampersand_written_as_an_entity_amp_passes(): void {
		$this->assertNull( OptionRules::value_error( 'blogname', 'Tom &amp; Jerry' ) );
	}

	/**
	 * A sanitizer that would store a different value refuses the value, even when the plain-text rule passes.
	 */
	public function test_a_sanitizer_that_would_change_the_value_refuses_it(): void {
		$this->on(
			'sanitize_option_blogname',
			static fn(): string => 'Changed by a filter'
		);

		$refused = OptionRules::value_error( 'blogname', 'Plain title' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'steadhand_invalid_input', $refused->get_error_code() );
	}

	/**
	 * A sanitizer that throws refuses the value.
	 */
	public function test_a_sanitizer_that_throws_refuses_the_value(): void {
		$this->on(
			'sanitize_option_blogname',
			static function (): never {
				throw new \RuntimeException( 'A sanitizer that fails.' );
			}
		);

		$this->assertSame( 'steadhand_invalid_input', OptionRules::value_error( 'blogname', 'Plain title' )->get_error_code() );
	}

	/**
	 * An option that is not on the allowlist has no value rule: the value rule refuses it.
	 */
	public function test_a_name_not_on_the_allowlist_is_refused_by_the_value_rule(): void {
		$this->assertSame( 'steadhand_invalid_input', OptionRules::value_error( 'siteurl', 'https://example.com' )->get_error_code() );
	}

	/**
	 * A missing option is refused, and the refusal is not a creation: the row is still missing afterwards.
	 */
	public function test_a_missing_option_is_refused_and_is_not_created(): void {
		$this->assertNull( OptionRules::stored_raw( self::EXTRA ), 'Precondition: the option does not exist.' );
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options[ self::EXTRA ] = array( 'max_length' => 100 );

				return $options;
			}
		);
		$this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( self::EXTRA, 'value' ) );

		$this->assert_refused_with( $result, 'steadhand_refused_option_missing', 409 );
		$this->assertNull( OptionRules::stored_raw( self::EXTRA ), 'The option must not be created.' );
	}

	/**
	 * An option the filter added that holds a serialized array is refused, and is not changed.
	 */
	public function test_a_filter_added_option_holding_a_serialized_array_is_refused_and_unchanged(): void {
		add_option( self::EXTRA, array( 'a' => 1 ), '', 'no' );
		$before = $this->stored_value( self::EXTRA );
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options[ self::EXTRA ] = array( 'max_length' => 100 );

				return $options;
			}
		);
		$this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( self::EXTRA, 'value' ) );

		$this->assert_refused_with( $result, 'steadhand_refused_serialized_option', 409 );
		$this->assertSame( $before, $this->stored_value( self::EXTRA ) );
	}

	/**
	 * An option the filter added that holds a plain string is changed, and that is the whole allowance the filter grants.
	 */
	public function test_a_filter_added_plain_text_option_can_be_changed(): void {
		add_option( self::EXTRA, 'before', '', 'no' );
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options[ self::EXTRA ] = array( 'max_length' => 100 );

				return $options;
			}
		);
		$this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( self::EXTRA, 'after' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'after', $this->stored_value( self::EXTRA ) );
	}

	/**
	 * A pre_option filter on the option makes the stored-value rule refuse it, and nothing changes.
	 */
	public function test_a_pre_option_filter_refuses_the_option(): void {
		$before = $this->stored_value( 'blogname' );
		$this->on(
			'pre_option_blogname',
			static fn(): string => 'Overridden in code'
		);

		$refused = OptionRules::stored_error( 'blogname' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'steadhand_refused_option_filtered', $refused->get_error_code() );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * An option stored as an empty string is a stored option, not a missing one: the read returns '' and the stored-value rule passes it.
	 * (wpdb::get_var() returns null for an empty string, which would have read it as missing.)
	 */
	public function test_an_option_stored_as_an_empty_string_is_present_not_missing(): void {
		update_option( 'blogdescription', '' );

		$this->assertSame( '', OptionRules::stored_raw( 'blogdescription' ) );
		$this->assertNull( OptionRules::stored_error( 'blogdescription' ) );
	}

	/**
	 * A plain option with no filter passes the stored-value rule.
	 */
	public function test_a_plain_stored_option_passes_the_stored_value_rule(): void {
		$this->assertNull( OptionRules::stored_error( 'blogname' ) );
	}
}
