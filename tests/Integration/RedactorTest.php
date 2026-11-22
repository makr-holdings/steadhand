<?php
/**
 * Unit tests for Steadhand\Redactor. The class is pure, so these tests need no WordPress state.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Steadhand\Redactor;

/**
 * Covers the patterns Redactor::text() removes, the key rule, the value walker and the failure behaviour.
 */
final class RedactorTest extends TestCase {

	private const MASK = '[redacted]';

	/**
	 * Asserts the exact redacted output of one input.
	 *
	 * @param string $expected Expected output.
	 * @param string $input    Input text.
	 */
	private function assertText( string $expected, string $input ): void {
		$this->assertSame( $expected, Redactor::text( $input ) );
	}

	public function test_authorization_basic_header_value_is_masked(): void {
		$this->assertText(
			"GET /x\nAuthorization: [redacted]\nHost: example.test",
			"GET /x\nAuthorization: Basic dXNlcjpzM2NyZXQ=\nHost: example.test"
		);
	}

	public function test_authorization_bearer_header_value_is_masked(): void {
		$this->assertText( 'Authorization: [redacted]', 'Authorization: Bearer abc.def.ghi' );
	}

	public function test_authorization_header_is_masked_after_any_scheme_and_in_any_case(): void {
		$this->assertText( 'authorization: [redacted]', 'authorization: Token t0k3nValue' );
	}

	public function test_cookie_and_set_cookie_header_values_are_masked(): void {
		$this->assertText(
			"Cookie: [redacted]\nSet-Cookie: [redacted]",
			"Cookie: wp_logged_in=alice%7C123; theme=x\nSet-Cookie: sid=abc; Path=/"
		);
	}

	public function test_url_userinfo_is_masked_and_the_host_kept(): void {
		$this->assertText(
			'https://[redacted]@example.com/path',
			'https://u:LIVESECRET1@example.com/path'
		);
	}

	public function test_query_parameter_with_a_sensitive_name_is_masked(): void {
		$this->assertText( 'https://x.test/?token=[redacted]&x=1', 'https://x.test/?token=abc&x=1' );
	}

	public function test_query_parameter_with_an_ordinary_name_is_kept(): void {
		$this->assertText( 'https://x.test/?page=2&sort=asc', 'https://x.test/?page=2&sort=asc' );
	}

	public function test_key_equals_value_is_masked(): void {
		$this->assertText( 'user=bob password=[redacted]', 'user=bob password=hunter2' );
	}

	public function test_key_colon_value_is_masked(): void {
		$this->assertText( 'password: [redacted]', 'password: hunter2' );
	}

	public function test_json_key_value_is_masked(): void {
		$this->assertText( '{"api_key":"[redacted]","n":1}', '{"api_key":"abc123xyz","n":1}' );
	}

	public function test_json_value_with_spaces_is_masked_whole(): void {
		$this->assertText( '{"secret":"[redacted]"}', '{"secret":"two words here"}' );
	}

	public function test_php_array_dump_key_value_is_masked(): void {
		$this->assertText( "'secret' => '[redacted]'", "'secret' => 'hunter2'" );
	}

	public function test_php_array_dump_with_brackets_is_masked(): void {
		$this->assertText( "['token'] => '[redacted]'", "['token'] => 'abc'" );
	}

	public function test_json_web_token_is_masked(): void {
		$this->assertText(
			'jwt [redacted] done',
			'jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl done'
		);
	}

	public function test_pem_private_key_block_is_masked_across_lines(): void {
		$this->assertText(
			"before\n[redacted]\nafter",
			"before\n-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA\nabc+/==\n-----END RSA PRIVATE KEY-----\nafter"
		);
	}

	public function test_unterminated_pem_block_is_masked_to_its_end(): void {
		$this->assertText( "x\n[redacted]", "x\n-----BEGIN PRIVATE KEY-----\nMIIEvQIBAD" );
	}

	public function test_public_pem_blocks_are_not_masked(): void {
		$public = "-----BEGIN PUBLIC KEY-----\nabc\n-----END PUBLIC KEY-----";

		$this->assertText( $public, $public );
	}

	public function test_github_tokens_are_masked(): void {
		$this->assertText( 'a [redacted] b', 'a ghp_' . str_repeat( 'A1', 18 ) . ' b' );
		$this->assertText( '[redacted]', 'gho_' . str_repeat( 'B2', 18 ) );
		$this->assertText( '[redacted]', 'ghs_' . str_repeat( 'C3', 18 ) );
		$this->assertText( '[redacted]', 'github_pat_11ABCDEFG0_abcdefghijklmnop' );
	}

	public function test_aws_access_key_ids_are_masked(): void {
		$this->assertText( 'id [redacted]', 'id AKIAIOSFODNN7EXAMPLE' );
		$this->assertText( '[redacted]', 'ASIAIOSFODNN7EXAMPLE' );
	}

	public function test_slack_tokens_are_masked(): void {
		$this->assertText( '[redacted]', 'xoxb-1234567890-abcdefghijk' );
		$this->assertText( '[redacted]', 'xoxp-1234567890-abcdefghijk' );
	}

	public function test_stripe_keys_are_masked(): void {
		$this->assertText( '[redacted]', 'sk_live_' . str_repeat( 'a1', 12 ) );
		$this->assertText( '[redacted]', 'rk_live_' . str_repeat( 'b2', 12 ) );
		$this->assertText( '[redacted]', 'sk_test_' . str_repeat( 'c3', 12 ) );
	}

	public function test_google_api_key_is_masked(): void {
		$this->assertText( '[redacted]', 'AIza' . str_repeat( 'x', 35 ) );
	}

	public function test_application_password_in_the_compact_form_is_masked(): void {
		$this->assertText( 'pw [redacted]', 'pw abCD1234efGH5678ijKL9012' );
	}

	public function test_application_password_in_the_grouped_form_is_masked(): void {
		$this->assertText( '[redacted]', 'AbCd 1234 EfGh 5678 IjKl 9012' );
	}

	public function test_sixty_four_character_hex_digest_is_masked(): void {
		$this->assertText( 'hash [redacted]', 'hash ' . hash( 'sha256', 'x' ) );
	}

	public function test_long_base64_secret_with_digits_is_masked(): void {
		$this->assertText( '[redacted]', 'Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo=' );
	}

	public function test_file_path_is_not_masked(): void {
		$this->assertText( 'includes/Abilities/GetDiagnostics.php', 'includes/Abilities/GetDiagnostics.php' );
	}

	public function test_long_hyphenated_slug_of_letters_only_is_not_masked(): void {
		$slug = 'this-is-a-very-long-hyphenated-slug-of-letters-only-and-long';

		$this->assertText( $slug, $slug );
	}

	public function test_email_addresses_become_an_email_marker(): void {
		$this->assertText( 'mail [email] now', 'mail admin@example.com now' );
	}

	public function test_wordpress_salt_constants_are_masked(): void {
		$this->assertText(
			"define( 'AUTH_KEY', '[redacted]' );",
			"define( 'AUTH_KEY', 'put your unique phrase here' );"
		);
	}

	public function test_wordpress_database_password_constant_is_masked(): void {
		$this->assertText( "define( 'DB_PASSWORD', '[redacted]' );", "define( 'DB_PASSWORD', 'dbsecret' );" );
	}

	public function test_ordinary_wordpress_constants_are_not_masked(): void {
		$this->assertText( "define( 'WP_DEBUG', true );", "define( 'WP_DEBUG', true );" );
	}

	public function test_ordinary_text_is_unchanged(): void {
		$line = 'Call to undefined function foo() in /var/www/x.php on line 3';

		$this->assertText( $line, $line );
	}

	public function test_invalid_utf8_is_replaced_and_valid_characters_are_kept(): void {
		$result = Redactor::text( "caf\xC3\xA9 \xFF token=abc \xC3\x28" );

		$this->assertSame( 1, preg_match( '//u', $result ), 'The result must be valid UTF-8.' );
		$this->assertStringContainsString( 'café', $result );
		$this->assertStringContainsString( 'token=[redacted]', $result );
		$this->assertStringNotContainsString( 'abc', $result );
	}

	public function test_one_megabyte_of_letters_is_handled_quickly(): void {
		$input = str_repeat( 'a', 1048576 );

		$start  = microtime( true );
		$result = Redactor::text( $input );

		$this->assertLessThan( 1.0, microtime( true ) - $start );
		$this->assertSame( $input, $result );
	}

	public function test_two_hundred_kilobytes_of_equals_and_slashes_is_handled_quickly(): void {
		$input = str_repeat( '=/', 102400 );

		$start = microtime( true );
		Redactor::text( $input );

		$this->assertLessThan( 1.0, microtime( true ) - $start );
	}

	public function test_repeated_markers_and_separators_are_handled_quickly(): void {
		$inputs = array(
			str_repeat( '-----BEGIN PRIVATE KEY-----', 40000 ),
			str_repeat( 'a.', 300000 ),
			str_repeat( 'x://', 100000 ),
			str_repeat( 'a=', 200000 ),
			str_repeat( 'password ', 100000 ),
		);

		foreach ( $inputs as $input ) {
			$start = microtime( true );
			Redactor::text( $input );

			$this->assertLessThan( 1.0, microtime( true ) - $start );
		}
	}

	public function test_a_failed_regular_expression_masks_the_whole_string(): void {
		$previous = ini_get( 'pcre.backtrack_limit' );
		$jit      = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Forces PCRE to honour the limit below for this test only.
		ini_set( 'pcre.backtrack_limit', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Simulates a regex failure. Restored in finally.

		try {
			$result = Redactor::text( 'a harmless line with token=abc and more words to scan' );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value read above.
			ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value read above.
		}

		$this->assertSame( self::MASK, $result, 'A regex failure must fail closed, never return the original text.' );
	}

	public function test_sensitive_key_names_are_recognised(): void {
		$keys = array(
			'password',
			'PASSWORD',
			'user_pass',
			'db_password',
			'passwd',
			'pwd',
			'clientSecret',
			'secret_key',
			'x-api-key',
			'api_key',
			'apiKey',
			'apikey',
			'auth_key',
			'AUTH_SALT',
			'Authorization',
			'auth_token',
			'access_token',
			'credential',
			'credentials',
			'Set-Cookie',
			'session_id',
			'nonce',
			'signature',
			'private',
			'key',
			'key_count',
			'content_hash',
			'keys',
		);

		foreach ( $keys as $key ) {
			$this->assertTrue( Redactor::is_sensitive_key( $key ), "$key must be sensitive." );
		}
	}

	public function test_ordinary_key_names_are_not_sensitive(): void {
		$keys = array(
			'keyword',
			'monkey',
			'turkey',
			'author',
			'authorize',
			'post_title',
			'status',
			'count',
			'passage',
			'compass',
			'hostname',
			'wp_debug_log',
			'memory_limit',
			'',
		);

		foreach ( $keys as $key ) {
			$this->assertFalse( Redactor::is_sensitive_key( $key ), "$key must not be sensitive." );
		}
	}

	public function test_value_masks_values_under_sensitive_keys_whatever_their_type(): void {
		$value = array(
			'password' => 123,
			'token'    => null,
			'secret'   => array( 'a' => 1 ),
			'title'    => 'Hello',
		);

		$this->assertSame(
			array(
				'password' => self::MASK,
				'token'    => self::MASK,
				'secret'   => self::MASK,
				'title'    => 'Hello',
			),
			Redactor::value( $value )
		);
	}

	public function test_value_redacts_strings_at_any_depth(): void {
		$value = array(
			'items' => array(
				array( 'note' => 'Authorization: Basic abc' ),
			),
		);

		$this->assertSame(
			array( 'items' => array( array( 'note' => 'Authorization: [redacted]' ) ) ),
			Redactor::value( $value )
		);
	}

	public function test_value_passes_scalars_through(): void {
		$this->assertSame( 1, Redactor::value( 1 ) );
		$this->assertSame( 2.5, Redactor::value( 2.5 ) );
		$this->assertTrue( Redactor::value( true ) );
		$this->assertFalse( Redactor::value( false ) );
		$this->assertNull( Redactor::value( null ) );
	}

	public function test_value_casts_objects_to_arrays_and_redacts_them(): void {
		$object = (object) array(
			'name'    => 'site',
			'api_key' => 'abc',
		);

		$this->assertSame(
			array(
				'name'    => 'site',
				'api_key' => self::MASK,
			),
			Redactor::value( $object )
		);
	}

	public function test_value_masks_everything_beyond_the_depth_limit(): void {
		$value = 'leaf';
		for ( $i = 0; $i < 25; $i++ ) {
			$value = array( $value );
		}

		$node  = Redactor::value( $value );
		$depth = 0;
		while ( is_array( $node ) && $depth < 25 ) {
			$node = $node[0];
			++$depth;
		}

		$this->assertSame( self::MASK, $node );
	}

	public function test_value_masks_a_resource_instead_of_failing(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opens an in-memory test stream, not a file on disk.
		$handle = fopen( 'php://memory', 'r' );

		try {
			$this->assertSame( self::MASK, Redactor::value( $handle ) );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the in-memory test stream opened above.
		}
	}

	/**
	 * Asserts that a redacted result is valid UTF-8 and can be encoded as JSON, which the tool output needs.
	 *
	 * @param string $result Redacted text.
	 */
	private function assertEncodable( string $result ): void {
		$this->assertSame( 1, preg_match( '//u', $result ), 'The redacted text must be valid UTF-8.' );
		$this->assertNotFalse( json_encode( $result ), 'The redacted text must encode as JSON.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The check is the raw encoder's own result.
	}

	public function test_a_long_bare_value_is_masked_whole_and_the_result_stays_valid_utf8(): void {
		$result = Redactor::text( 'password=' . str_repeat( 'a', 1023 ) . "\u{e9} tail" );

		$this->assertSame( 'password=[redacted] tail', $result );
		$this->assertEncodable( $result );
	}

	public function test_a_bare_value_of_any_length_leaks_nothing_past_the_first_kilobyte(): void {
		$result = Redactor::text( 'password=' . str_repeat( 'A', 2000 ) );

		$this->assertSame( 'password=[redacted]', $result );
		$this->assertStringNotContainsString( 'AAAA', $result );
	}

	public function test_a_double_quoted_value_longer_than_a_kilobyte_is_masked(): void {
		$result = Redactor::text( 'password="' . str_repeat( 'b', 1500 ) . '"' );

		$this->assertSame( 'password="[redacted]"', $result );
		$this->assertStringNotContainsString( 'bbbb', $result );
	}

	public function test_a_single_quoted_value_longer_than_a_kilobyte_is_masked(): void {
		$result = Redactor::text( "password='" . str_repeat( 'c', 1500 ) . "'" );

		$this->assertSame( "password='[redacted]'", $result );
		$this->assertStringNotContainsString( 'cccc', $result );
	}

	public function test_an_escaped_double_quote_inside_a_quoted_value_does_not_end_it(): void {
		$result = Redactor::text( 'password="hun\\"ter2x"' );

		$this->assertSame( 'password="[redacted]"', $result );
		$this->assertStringNotContainsString( 'ter2x', $result );
	}

	public function test_an_escaped_single_quote_inside_a_quoted_value_does_not_end_it(): void {
		$result = Redactor::text( "password='it\\'s-hun7er'" );

		$this->assertSame( "password='[redacted]'", $result );
		$this->assertStringNotContainsString( 'hun7er', $result );
	}

	public function test_database_access_denied_user_and_host_are_masked(): void {
		$this->assertText(
			"Access denied for user '[redacted]'@'[redacted]' (using password: [redacted])",
			"Access denied for user 'wp_dbuser'@'db-prod-7.internal' (using password: YES)"
		);
	}

	public function test_database_unknown_server_host_is_masked(): void {
		$this->assertText( "Unknown MySQL server host '[redacted]' (-2)", "Unknown MySQL server host 'db-prod-7.internal' (-2)" );
	}

	public function test_database_getaddrinfo_host_is_masked(): void {
		$this->assertText( 'getaddrinfo for [redacted] failed', 'getaddrinfo for db-prod-7.internal.example.net failed' );
	}

	public function test_database_cant_connect_host_and_port_are_masked(): void {
		$this->assertText( "Can't connect to MySQL server on '[redacted]' (111)", "Can't connect to MySQL server on 'db.internal:3306' (111)" );
	}

	public function test_database_sqlstate_with_a_getaddrinfo_host_is_masked(): void {
		$this->assertText(
			'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for [redacted] failed: Name or service not known',
			'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db.internal failed: Name or service not known'
		);
	}

	public function test_database_getaddrinfo_failed_with_a_dotted_host_is_masked(): void {
		$this->assertText(
			'php_network_getaddresses: getaddrinfo failed: [redacted]',
			'php_network_getaddresses: getaddrinfo failed: db-prod-7.internal'
		);
	}

	public function test_database_connection_messages_without_a_host_are_kept(): void {
		$this->assertText( 'SQLSTATE[HY000] [2002] Connection refused', 'SQLSTATE[HY000] [2002] Connection refused' );
		$this->assertText( 'getaddrinfo failed: Name or service not known', 'getaddrinfo failed: Name or service not known' );
	}

	public function test_keys_beginning_with_underscores_are_sensitive(): void {
		$this->assertText( '_password=[redacted]', '_password=hunter2x' );
		$this->assertText( '_token=[redacted]', '_token=abc123' );
		$this->assertText( '_wpnonce=[redacted]', '_wpnonce=abc123' );
		$this->assertText( '_ajax_nonce: [redacted]', '_ajax_nonce: abc123' );
		$this->assertText( '__secret=[redacted]', '__secret=s3cr3t' );
	}

	public function test_additional_sensitive_key_words_mask_their_values(): void {
		$words = array( 'pw', 'pswd', 'passphrase', 'sig', 'sas', 'sid', 'sessid', 'phpsessid', 'dsn', 'connstr', 'conn_str', 'connection_string', 'jwt', 'bearer', 'otp', 'totp', 'mfa', 'recovery', 'license', 'licence', 'passwords', 'tokens', 'secrets', 'keys', 'credentials', 'wpnonce' );

		foreach ( $words as $word ) {
			$this->assertText( "$word=[redacted]", "$word=Zz9value" );
		}
	}

	public function test_sensitive_cookie_names_mask_the_whole_value(): void {
		$this->assertText( 'wordpress_logged_in_9f8e=[redacted]; path=/', 'wordpress_logged_in_9f8e=admin%7C1700000000%7Cabc; path=/' );
		$this->assertText( 'wordpress_sec_1a=[redacted]', 'wordpress_sec_1a=admin|1|x' );
		$this->assertText( 'wp-settings-1=[redacted]', 'wp-settings-1=libraryContent=browse' );
		$this->assertText( 'wordpress_test_cookie=[redacted]', 'wordpress_test_cookie=WP+Cookie+check' );
	}

	public function test_ordinary_key_words_that_only_look_similar_are_kept(): void {
		$this->assertText( 'keyword=abc', 'keyword=abc' );
		$this->assertText( 'monkey=banana', 'monkey=banana' );
		$this->assertText( 'author=jane', 'author=jane' );
	}

	public function test_a_basic_credential_in_an_array_dump_is_masked(): void {
		$this->assertText( '[HTTP_AUTHORIZATION] => [redacted]', '[HTTP_AUTHORIZATION] => Basic YWRtaW46cGFzcw==' );
	}

	public function test_bearer_negotiate_and_digest_credentials_are_masked_after_any_separator(): void {
		$this->assertText( 'header: [redacted]', 'header: Basic YWRtaW46cGFzcw==' );
		$this->assertText( 'auth=[redacted]', 'auth=Bearer tok3nValue1' );
		$this->assertText( '[redacted]', 'Negotiate YIIBgwYGKwYBBQUCoIIBdzCCAXOgDTALBgkqhkiG9w0BAQ==' );
		$this->assertText( '[redacted]', 'Digest username="bob", realm="x", response="abc123"' );
	}

	public function test_an_authentication_word_followed_by_plain_words_is_kept(): void {
		$this->assertText( 'Basic Authentication required', 'Basic Authentication required' );
	}

	public function test_url_userinfo_with_an_at_sign_in_the_password_is_masked_to_the_last_at(): void {
		$this->assertText( 'postgres://[redacted]@host/db', 'postgres://u:S3cr@tPass@host/db' );
	}

	public function test_url_userinfo_with_query_and_fragment_characters_is_masked(): void {
		$this->assertText( 'mysql://[redacted]@host/db', 'mysql://u:S3c?ret#Pass@host/db' );
	}

	public function test_an_email_address_in_a_url_path_is_not_taken_for_userinfo(): void {
		$this->assertText( 'https://example.com/users/[email]', 'https://example.com/users/bob@example.com' );
	}

	public function test_an_at_sign_in_a_query_string_after_the_host_is_not_taken_for_userinfo(): void {
		$this->assertText( 'https://example.com/?next=[email]', 'https://example.com/?next=bob@x.test' );
	}

	public function test_bracketed_values_under_sensitive_keys_are_masked_whole(): void {
		$this->assertText( 'auth_token: [redacted]', 'auth_token: [x]' );
		$this->assertText( '"tokens": [redacted]', '"tokens": ["a","b"]' );
		$this->assertText( "'secret' => [redacted]", "'secret' => array( 'a', 'b' )" );
		$this->assertText( 'token=[redacted]', 'token=(x)' );
		$this->assertText( 'token=[redacted]', 'token={x}' );
	}

	public function test_a_serialized_sensitive_pair_has_its_value_masked(): void {
		$this->assertText( 's:8:"password";s:10:"[redacted]";', 's:8:"password";s:10:"Hunter2xyz";' );
	}

	public function test_a_serialized_ordinary_pair_is_kept(): void {
		$this->assertText( 's:4:"name";s:5:"Alice";', 's:4:"name";s:5:"Alice";' );
	}

	public function test_phpass_bcrypt_wordpress_and_argon2_hashes_are_masked(): void {
		$this->assertText( '[redacted]', '$P$' . str_repeat( 'B', 31 ) );
		$this->assertText( '[redacted]', '$H$' . str_repeat( 'C', 31 ) );
		$this->assertText( '[redacted]', '$2y$10$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQ' );
		$this->assertText( '[redacted]', '$wp$2y$10$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQ' );
		$this->assertText( '[redacted]', '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG' );
		$this->assertText( '[redacted]', '$6$rounds$abcdefABCDEF0123' );
	}

	public function test_a_thirty_two_character_mixed_string_is_masked(): void {
		$this->assertText( '[redacted]', 'd41d8cd98f00b204e9800998ecf8427e' );
		$this->assertText( '[redacted]', 'a1' . str_repeat( 'b', 30 ) );
	}

	public function test_a_thirty_one_character_mixed_string_is_kept(): void {
		$this->assertText( 'abcdefghijklmnopqrstuvwxyz12345', 'abcdefghijklmnopqrstuvwxyz12345' );
	}

	public function test_prefixed_vendor_tokens_are_masked_whole(): void {
		$this->assertText( '[redacted]', 'glpat-abcdefghij0123456789' );
		$this->assertText( '[redacted]', 'whsec_abcdefghijklmnopqrstuvwx0123' );
		$this->assertText( '[redacted]', 'SK0123456789abcdef0123456789abcdef' );
		$this->assertText( '[redacted]', 'AC0123456789abcdef0123456789abcdef' );
		$this->assertText( '[redacted]', 'key-0123456789abcdef0123456789abcdef' );
		$this->assertText( '[redacted]', 'SG.abcdefghij1234.ABCDEFGHIJ0123456789abcd' );
		$this->assertText( '[redacted]', 'npm_abcdefghijklmnopqrstuvwxyz0123456789' );
		$this->assertText( '[redacted]', 'hf_abcdefghijklmnopqrstuvwxyz0123456' );
		$this->assertText( '[redacted]', 'sk-proj-abcdefghijklmnopqrstuvwxyz0123' );
		$this->assertText( '[redacted]', 'sk-ant-api03-abcdefghijklmnopqrstuv' );
	}

	public function test_a_uuid_and_a_css_class_list_are_kept(): void {
		$this->assertText( '550e8400-e29b-41d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440000' );
		$this->assertText(
			'class="wp-block-group has-text-color has-link-color col-md-6 col-lg-4"',
			'class="wp-block-group has-text-color has-link-color col-md-6 col-lg-4"'
		);
	}

	public function test_a_path_a_hook_name_a_version_and_an_undefined_key_message_are_kept(): void {
		$this->assertText( '/var/www/html/wp-content/plugins/steadhand/includes/Abilities/GetDiagnostics.php', '/var/www/html/wp-content/plugins/steadhand/includes/Abilities/GetDiagnostics.php' );
		$this->assertText( 'Hook my_token_refresh_hook fired', 'Hook my_token_refresh_hook fired' );
		$this->assertText( 'Version 6.9.1 of WordPress', 'Version 6.9.1 of WordPress' );
		$this->assertText( 'Undefined array key "token"', 'Undefined array key "token"' );
	}

	public function test_application_passwords_with_dashes_or_dots_are_masked(): void {
		$this->assertText( '[redacted]', 'abcd-1234-ijkl-mnop-qrst-uvwx' );
		$this->assertText( '[redacted]', 'abcd.1234.ijkl.mnop.qrst.uvwx' );
	}

	public function test_a_date_and_time_are_not_taken_for_an_application_password(): void {
		$this->assertText( '2026-11-22 10:00:00', '2026-11-22 10:00:00' );
	}

	public function test_a_define_value_with_the_other_quote_is_masked(): void {
		$this->assertText( 'define( \'AUTH_KEY\', "[redacted]" );', 'define( \'AUTH_KEY\', "it\'s a secret" );' );
	}

	public function test_a_define_value_with_escaped_quotes_is_masked(): void {
		$this->assertText( "define('DB_PASSWORD', '[redacted]' );", "define('DB_PASSWORD', 'pa\\'ss' );" );
	}

	public function test_a_define_of_an_ordinary_constant_is_kept(): void {
		$this->assertText( "define('WP_MEMORY_LIMIT', '256M');", "define('WP_MEMORY_LIMIT', '256M');" );
	}

	public function test_luhn_valid_card_numbers_are_masked_however_they_are_separated(): void {
		$this->assertText( 'card [card] on file', 'card 4111 1111 1111 1111 on file' );
		$this->assertText( '[card]', '4111-1111-1111-1111' );
		$this->assertText( '[card]', '4111111111111111' );
	}

	public function test_a_sixteen_digit_number_that_fails_luhn_is_kept(): void {
		$this->assertText( '4111111111111112', '4111111111111112' );
	}

	public function test_timestamps_line_numbers_and_ids_are_kept(): void {
		$this->assertText( 'at 1700000000 on line 1234', 'at 1700000000 on line 1234' );
		$this->assertText( 'ms 1732233600003', 'ms 1732233600003' );
		$this->assertText( 'id 123456789012', 'id 123456789012' );
	}

	public function test_punycode_and_utf8_email_domains_are_masked(): void {
		$this->assertText( 'mail [email] now', 'mail jane@xn--bcher-kva.example now' );
		$this->assertText( 'Contact [email] today', "Contact jos\u{e9}@b\u{fc}cher.de today" );
	}

	public function test_control_characters_and_ansi_sequences_are_removed(): void {
		$this->assertText( 'okdone', "ok\x07\x1b[2Jdone\x00" );
		$this->assertText( "a\tb\nc", "a\tb\nc" );
		$this->assertText( 'x31my', "x\u{9b}31my" );
	}

	/**
	 * Pathological inputs, each 1 MB: runs of one character or token that the rules scan for.
	 *
	 * @return array<string, string>
	 */
	public static function pathological_inputs(): array {
		$size = 1048576;

		return array(
			'equals'       => str_repeat( '=', $size ),
			'slash'        => str_repeat( '/', $size ),
			'colon'        => str_repeat( ':', $size ),
			'at'           => str_repeat( '@', $size ),
			'dollar'       => str_repeat( '$', $size ),
			'digits'       => str_repeat( '1', $size ),
			'jwt_prefix'   => str_repeat( 'eyJa.', (int) ( $size / 5 ) ),
			'key_equals'   => str_repeat( 'key=', (int) ( $size / 4 ) ),
			'quotes'       => str_repeat( '"', $size ),
			'basic'        => str_repeat( 'Basic ', (int) ( $size / 6 ) ),
			'serialized'   => str_repeat( 's:1:"', (int) ( $size / 6 ) ),
			'define'       => str_repeat( 'define(', (int) ( $size / 7 ) ),
			'userinfo'     => 'http://' . str_repeat( 'a:', (int) ( $size / 2 ) ) . '@',
			'at_in_scheme' => 'https://' . str_repeat( 'a@', (int) ( $size / 2 ) ),
		);
	}

	/**
	 * Every pathological input finishes in under three seconds, with the JIT on and with it off.
	 */
	public function test_pathological_inputs_finish_quickly_with_and_without_pcre_jit(): void {
		$jit = ini_get( 'pcre.jit' );

		try {
			foreach ( array( '1', '0' ) as $setting ) {
				ini_set( 'pcre.jit', $setting ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test only: the guard must hold with the JIT on and off; restored in finally.

				foreach ( self::pathological_inputs() as $name => $input ) {
					$started = microtime( true );
					$result  = Redactor::text( $input );
					$elapsed = microtime( true ) - $started;

					$this->assertLessThan( 3.0, $elapsed, "The $name input took $elapsed seconds with pcre.jit=$setting." );
					$this->assertNotSame( '', $result, 'A pathological input must not disappear silently.' );
					$this->assertSame( 1, preg_match( '//u', $result ), "The $name result must be valid UTF-8." );
				}
			}
		} finally {
			ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value read above.
		}
	}

	public function test_a_rule_that_cuts_a_multibyte_character_leaves_valid_utf8(): void {
		// A host of 200 characters, which is 400 bytes. It is masked whole, and the output stays valid UTF-8.
		$result = Redactor::text( 'getaddrinfo for ' . str_repeat( "\u{e9}", 200 ) . ' failed' );

		$this->assertSame( 'getaddrinfo for [redacted] failed', $result );
		$this->assertEncodable( $result );
	}
}
