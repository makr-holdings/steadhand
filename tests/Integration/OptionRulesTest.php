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
	 * The hard_blocked() check on its own: the two built-in options are not blocked; every core option, prefix, ending, fragment and word is; the check
	 * ignores case. The allowlist calls this, so a name it blocks never reaches a writer.
	 *
	 * @dataProvider hard_blocked_unit_names
	 *
	 * @param string $name    The option name.
	 * @param bool   $blocked Whether it is blocked.
	 */
	public function test_hard_blocked_names_on_their_own( string $name, bool $blocked ): void {
		$this->assertSame( $blocked, OptionRules::hard_blocked( $name ), $name );
	}

	/**
	 * Names for hard_blocked(): one of each group, and the case variants.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function hard_blocked_unit_names(): array {
		return array(
			'blogname'                  => array( 'blogname', false ),
			'blogdescription'           => array( 'blogdescription', false ),
			'upper case blogname'       => array( 'BLOGNAME', false ),
			'a plain option'            => array( 'probe_option', false ),
			'a tagline extra'           => array( 'my_tagline_extra', false ),
			'siteurl'                   => array( 'siteurl', true ),
			'mixed case siteurl'        => array( 'SiteURL', true ),
			'active_plugins'            => array( 'active_plugins', true ),
			'a core option, fresh_site' => array( 'fresh_site', true ),
			'a core option, nav menus'  => array( 'nav_menu_options', true ),
			'a core option, mixed case' => array( 'use_balanceTags', true ),
			'the cron option'           => array( 'cron', true ),
			'the roles option, prefix'  => array( 'wp_user_roles', true ),
			'the capabilities option'   => array( 'wp_capabilities', true ),
			'a user level option'       => array( 'wp_user_level', true ),
			'widget_text'               => array( 'widget_text', true ),
			'a theme mod'               => array( 'theme_mods_twentytwentyfive', true ),
			'a steadhand option'        => array( 'steadhand_db_version', true ),
			'a transient'               => array( '_transient_x', true ),
			'a site transient'          => array( '_site_transient_x', true ),
			'an api key'                => array( 'my_api_key', true ),
			'an oauth token'            => array( 'oauth_token', true ),
			'a license key'             => array( 'license_key', true ),
			'a licence'                 => array( 'licence_x', true ),
			'a cookie name'             => array( 'cookie_name', true ),
			'a session timeout'         => array( 'session_timeout', true ),
			'a core option, notes'      => array( 'wp_notes_notify', true ),
			'a core option, gzip'       => array( 'gzipcompression', true ),
			'a core option, embed'      => array( 'embed_size_w', true ),
			'a core option, rss'        => array( 'rss_language', true ),
			'a core option, comments'   => array( 'comment_whitelist', true ),
			'an author word'            => array( 'myplugin_author_name', true ),
			'a keyword word'            => array( 'focus_keyword', true ),
			'a pwd word'                => array( 'pwd_x', true ),
			'a private word'            => array( 'private_note', true ),
			'a webhook word'            => array( 'webhook_url', true ),
			'a dsn word'                => array( 'dsn_x', true ),
			'an smtp word'              => array( 'smtp_host', true ),
			'a jwt word'                => array( 'jwt_x', true ),
			'an otp word'               => array( 'otp_x', true ),
			'a cert word'               => array( 'cert_x', true ),
			'a hash word'               => array( 'my_hash', true ),
			'a signature word'          => array( 'x_signature', true ),
			'a bearer word'             => array( 'bearer_x', true ),
			'a password word'           => array( 'mypassword', true ),
			'a secret'                  => array( 'secret_x', true ),
			'a nonce'                   => array( 'nonce_x', true ),
			'a salt'                    => array( 'salt_x', true ),
			'a credential'              => array( 'credential_x', true ),
		);
	}

	/**
	 * Every key word the redactor uses is a hard-block word here too, so the two rules cannot drift apart. The two built-in options are
	 * never blocked.
	 */
	public function test_every_redactor_key_word_is_a_hard_block_word(): void {
		foreach ( \Steadhand\Redactor::key_words() as $word ) {
			$this->assertTrue( OptionRules::hard_blocked( 'x_' . $word . '_y' ), $word );
		}
		$this->assertFalse( OptionRules::hard_blocked( 'blogname' ) );
		$this->assertFalse( OptionRules::hard_blocked( 'blogdescription' ) );
	}

	/**
	 * A value that is not valid UTF-8 gets its own message, not the length message.
	 */
	public function test_invalid_utf8_has_its_own_message(): void {
		$refused = OptionRules::value_error( 'blogname', "bad\xC3(" );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertStringContainsString( 'UTF-8', $refused->get_error_message() );
		$this->assertStringNotContainsString( 'characters long', $refused->get_error_message() );
	}

	/**
	 * With no filter, the allowlist is exactly the two built-in options and their limits.
	 */
	public function test_the_default_allowlist_is_the_two_built_in_options(): void {
		$this->assertSame(
			array(
				'blogname'        => array(
					'max_length' => 200,
					'min_length' => 1,
				),
				'blogdescription' => array(
					'max_length' => 500,
					'min_length' => 0,
				),
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
			'a core option'      => array( 'fresh_site' ),
			'a word option'      => array( 'my_api_key' ),
			'a cookie option'    => array( 'cookie_name' ),
			'a session option'   => array( 'session_timeout' ),
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
		$this->assertSame(
			array(
				'max_length' => 120,
				'min_length' => 0,
			),
			$allowed[ self::EXTRA ]
		);
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
			'a script tag'              => array( '<script>x</script>' ),
			'a bare less-than'          => array( 'a < b' ),
			'a named entity'            => array( 'a &nbsp; b' ),
			'a numeric entity'          => array( 'a &#38; b' ),
			'an entity written as text' => array( 'Tom &amp; Jerry' ),
			'a numeric quote entity'    => array( 'Joe &#039;s' ),
			'a control character'       => array( "a\x07b" ),
			'a newline'                 => array( "a\nb" ),
			'leading space'             => array( ' a' ),
			'trailing space'            => array( 'a ' ),
			'repeated space'            => array( 'a  b' ),
			'a no-break space'          => array( "a\u{00A0}b" ),
			'a zero-width space'        => array( "a\u{200B}b" ),
			'percent-encoding'          => array( 'a%20b' ),
			'invalid utf-8'             => array( "bad\xC3(" ),
			'an integer'                => array( 42 ),
			'an array'                  => array( array( 'a' ) ),
		);
	}

	/**
	 * An ampersand written as &amp; is plain text, and passes.
	 */
	public function test_a_bare_ampersand_and_quotes_are_display_text_and_pass(): void {
		$this->assertNull( OptionRules::value_error( 'blogname', 'Tom & Jerry' ) );
		$this->assertNull( OptionRules::value_error( 'blogname', "Joe's Pizza" ) );
		$this->assertNull( OptionRules::value_error( 'blogname', 'Say "hi"' ) );
	}

	/**
	 * The stored form of display text is HTML-escaped, and it decodes back to the text sent. This is what makes the round trip exact.
	 */
	public function test_the_stored_form_of_display_text_is_escaped_and_decodes_back(): void {
		$this->assertSame( 'Joe&#039;s Pizza', OptionRules::stored_form( 'blogname', "Joe's Pizza" ) );
		$this->assertSame( 'A &amp; B', OptionRules::stored_form( 'blogname', 'A & B' ) );
		$this->assertSame( '&quot;Q&quot;', OptionRules::stored_form( 'blogname', '"Q"' ) );
		$this->assertSame( "Joe's Pizza", OptionRules::display( 'Joe&#039;s Pizza' ) );
	}

	/**
	 * An entity sent as text would not read back as sent, so it is refused, whichever entity it is.
	 *
	 * @dataProvider entities_sent_as_text
	 *
	 * @param string $value The value sent.
	 */
	public function test_an_entity_sent_as_text_is_refused( string $value ): void {
		$refused = OptionRules::value_error( 'blogname', $value );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'steadhand_invalid_input', $refused->get_error_code() );
	}

	/**
	 * Entities sent as text.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function entities_sent_as_text(): array {
		return array(
			'an amp entity' => array( 'A &amp; B' ),
			'an apostrophe' => array( 'Joe&#039;s' ),
			'a less-than'   => array( 'a &lt; b' ),
			'a named copy'  => array( 'Tom &copy; Jerry' ),
		);
	}

	/**
	 * Serialized values are refused, because update_option() would serialize them. Each example is one is_serialized() accepts.
	 *
	 * @dataProvider serialized_values
	 *
	 * @param string $value The value sent.
	 */
	public function test_a_value_that_looks_serialized_is_refused( string $value ): void {
		$refused = OptionRules::value_error( 'blogdescription', $value );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'steadhand_invalid_input', $refused->get_error_code() );
	}

	/**
	 * Serialized examples.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function serialized_values(): array {
		return array(
			'null'                 => array( 'N;' ),
			'false'                => array( 'b:0;' ),
			'an integer'           => array( 'i:1;' ),
			'an empty array'       => array( 'a:0:{}' ),
			'a float'              => array( 'd:1.5;' ),
			'a string'             => array( 's:1:"a";' ),
			'an integer with text' => array( 'i:5; the top five' ),
			'a boolean with text'  => array( 'b:1;more' ),
			'an array with a name' => array( "a:3: Joe's" ),
		);
	}

	/**
	 * A stored name is matched exactly: a row whose name differs only in case is not the option asked for.
	 */
	public function test_a_row_with_another_spelling_of_the_name_is_not_the_option(): void {
		$this->assertNull( OptionRules::stored_raw( 'BlogName' ) );
		$this->assertNull( OptionRules::stored_raw( 'BLOGDESCRIPTION' ) );
		$this->assertIsString( OptionRules::stored_raw( 'blogname' ) );
	}

	/**
	 * An empty site title is refused by its minimum; an empty tagline is accepted.
	 */
	public function test_an_empty_title_is_refused_and_an_empty_tagline_is_accepted(): void {
		$refused = OptionRules::value_error( 'blogname', '' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'steadhand_invalid_input', $refused->get_error_code() );
		$this->assertNull( OptionRules::value_error( 'blogdescription', '' ) );
	}

	/**
	 * The minimum is read from the filter. A definition with a minimum above its maximum is dropped, a minimum that is not an integer is
	 * dropped, and a built-in name that gives no minimum keeps its built-in one (stricter, so the title stays non-empty).
	 */
	public function test_the_filter_checks_min_length(): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options['too_big_min'] = array(
					'max_length' => 10,
					'min_length' => 11,
				);
				$options['text_min']    = array(
					'max_length' => 10,
					'min_length' => '2',
				);
				$options['no_min']      = array( 'max_length' => 10 );
				$options['blogname']    = array( 'max_length' => 200 );

				return $options;
			}
		);

		$allowed = OptionRules::allowed();

		$this->assertArrayNotHasKey( 'too_big_min', $allowed );
		$this->assertArrayNotHasKey( 'text_min', $allowed );
		$this->assertSame( 0, $allowed['no_min']['min_length'] );
		$this->assertSame( 1, $allowed['blogname']['min_length'], 'A built-in name keeps its built-in minimum.' );
	}

	/**
	 * A filter-added name whose case differs from a kept name is dropped, so BlogName cannot stand beside blogname.
	 */
	public function test_the_filter_cannot_add_a_case_duplicate_of_a_kept_name(): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options['BlogName'] = array( 'max_length' => 50 );

				return $options;
			}
		);

		$allowed = OptionRules::allowed();

		$this->assertArrayNotHasKey( 'BlogName', $allowed );
		$this->assertSame( 200, $allowed['blogname']['max_length'], 'The built-in entry is the one kept.' );
	}

	/**
	 * A purely numeric name is an integer key in PHP, so the filter cannot add it: the entry is dropped.
	 */
	public function test_a_numeric_name_is_dropped(): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options['123'] = array( 'max_length' => 10 );

				return $options;
			}
		);

		$this->assertSame( array( 'blogname', 'blogdescription' ), array_keys( OptionRules::allowed() ) );
	}

	/**
	 * Core registers no callback on either built-in option, so the stored-value rule passes them, and the tests can rely on that.
	 */
	public function test_core_registers_no_read_filter_on_the_built_in_options(): void {
		foreach ( array( 'blogname', 'blogdescription' ) as $name ) {
			$this->assertFalse( has_filter( 'pre_option_' . $name ), "pre_option_$name" );
			$this->assertFalse( has_filter( 'option_' . $name ), "option_$name" );
			$this->assertFalse( has_filter( 'default_option_' . $name ), "default_option_$name" );
		}
		$this->assertFalse( has_filter( 'pre_option' ), 'pre_option' );
		$this->assertFalse( has_filter( 'alloptions' ), 'alloptions' );
	}

	/**
	 * Each read-side callback makes the stored-value rule refuse the option: a pre_option_ callback, the generic pre_option callback, an
	 * option_ callback and a default_option_ callback.
	 *
	 * @dataProvider read_side_hooks
	 *
	 * @param string $hook The hook name.
	 */
	public function test_a_read_side_callback_refuses_the_option( string $hook ): void {
		$this->on(
			$hook,
			static function ( mixed $value ): mixed {
				return $value;
			}
		);

		$refused = OptionRules::stored_error( 'blogname' );

		$this->assertInstanceOf( \WP_Error::class, $refused, $hook );
		$this->assertSame( 'steadhand_refused_option_filtered', $refused->get_error_code(), $hook );
	}

	/**
	 * The hooks a read-side callback can be on.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function read_side_hooks(): array {
		return array(
			'pre_option_blogname'     => array( 'pre_option_blogname' ),
			'pre_option (generic)'    => array( 'pre_option' ),
			'option_blogname'         => array( 'option_blogname' ),
			'default_option_blogname' => array( 'default_option_blogname' ),
			'alloptions'              => array( 'alloptions' ),
		);
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
