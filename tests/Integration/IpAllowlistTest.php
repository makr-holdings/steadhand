<?php
/**
 * The per-agent IP allowlist helpers: parsing, storage, matching and the client address. The refused forms come first.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\IpAllowlist;

/**
 * Covers SPEC §4.1 and D28 for includes/IpAllowlist.php.
 */
final class IpAllowlistTest extends TestCase {

	/**
	 * The $_SERVER keys this test may change, with their values before the test, restored in tearDown.
	 *
	 * @var array<string, mixed>
	 */
	private array $server_before = array();

	/**
	 * Filters this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable}>
	 */
	private array $added = array();

	protected function setUp(): void {
		parent::setUp();

		foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Saved and restored, never printed.
			$this->server_before[ $key ] = $_SERVER[ $key ] ?? null;
		}
	}

	protected function tearDown(): void {
		foreach ( $this->added as $added ) {
			remove_filter( 'steadhand_client_ip', $added[1], PHP_INT_MAX );
		}
		$this->added = array();

		foreach ( $this->server_before as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		parent::tearDown();
	}

	/**
	 * Adds a steadhand_client_ip filter that this test removes again.
	 *
	 * @param callable $callback The callback.
	 */
	private function client_filter( callable $callback ): void {
		add_filter( 'steadhand_client_ip', $callback, PHP_INT_MAX, 2 );
		$this->added[] = array( 'steadhand_client_ip', $callback );
	}

	/**
	 * Every form the parser must refuse. Each one is a single line that yields no entry and exactly one error.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused_lines(): array {
		return array(
			'hostname'             => array( 'example.com' ),
			'hostname with CIDR'   => array( 'example.com/24' ),
			'IPv4 with port'       => array( '203.0.113.5:443' ),
			'IPv6 in brackets'     => array( '[2001:db8::1]' ),
			'IPv6 with port'       => array( '[2001:db8::1]:443' ),
			'zone id'              => array( 'fe80::1%eth0' ),
			'zero prefix'          => array( '203.0.113.0/0' ),
			'IPv4 /7'              => array( '203.0.113.0/7' ),
			'IPv6 /15'             => array( '2001:db8::/15' ),
			'IPv4 /33'             => array( '203.0.113.0/33' ),
			'IPv6 /129'            => array( '2001:db8::/129' ),
			'space inside'         => array( '203.0.113. 5' ),
			'trailing text'        => array( '203.0.113.5 office' ),
			'empty prefix'         => array( '203.0.113.0/' ),
			'prefix with a letter' => array( '203.0.113.0/2a' ),
			'three octets'         => array( '1.2.3' ),
			'five octets'          => array( '1.2.3.4.5' ),
			'octal-looking octet'  => array( '010.0.0.1' ),
			'octal-looking in v6'  => array( '::ffff:010.0.0.1' ),
			'mapped IPv6 entry'    => array( '::ffff:203.0.113.5' ),
			'two slashes'          => array( '203.0.113.0/24/8' ),
			'a 50-character line'  => array( str_repeat( '1', 50 ) ),
			'a letter in IPv4'     => array( '203.0.113.x' ),
			'control character'    => array( "203.0.113.5\x01" ),
			'space inside a line'  => array( '203.0.113. 5' ),
			'tab inside a line'    => array( "203.0.\t113.5" ),
		);
	}

	/**
	 * Each refused line gives no entry and one error.
	 *
	 * @dataProvider refused_lines
	 * @param string $line The line.
	 */
	public function test_every_refused_form_yields_no_entry_and_one_error( string $line ): void {
		$parsed = IpAllowlist::parse( $line );

		$this->assertSame( array(), $parsed['entries'], "The line '$line' must not be stored." );
		$this->assertCount( 1, $parsed['errors'], "The line '$line' must give one error." );
	}

	/**
	 * Spaces, tabs and CRs at the ends of a line are not part of the entry (copy-paste), so these are accepted.
	 *
	 * @dataProvider surrounded_lines
	 * @param string $line  The line as typed.
	 * @param string $entry The canonical entry it stores.
	 */
	public function test_whitespace_at_the_ends_of_a_line_is_trimmed( string $line, string $entry ): void {
		$parsed = IpAllowlist::parse( $line );

		$this->assertSame( array(), $parsed['errors'] );
		$this->assertSame( array( $entry ), $parsed['entries'] );
	}

	/**
	 * Lines with whitespace at their ends, and the entry each one stores.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function surrounded_lines(): array {
		return array(
			'leading space'  => array( ' 203.0.113.5', '203.0.113.5' ),
			'trailing space' => array( '203.0.113.5 ', '203.0.113.5' ),
			'tab both ends'  => array( "\t203.0.113.5\t", '203.0.113.5' ),
			'CR at the end'  => array( "203.0.113.5\r", '203.0.113.5' ),
		);
	}

	/**
	 * A prefix with a leading zero is digits only, so it is accepted and stored as its number.
	 */
	public function test_a_prefix_with_a_leading_zero_is_accepted_and_stored_as_its_number(): void {
		$this->assertSame( array( '203.0.0.0/8' ), IpAllowlist::parse( '203.0.113.0/08' )['entries'] );
	}

	public function test_an_error_line_is_printable_and_short(): void {
		$parsed = IpAllowlist::parse( "203.0.113.5\x01<b>" . str_repeat( 'x', 200 ) );

		$this->assertCount( 1, $parsed['errors'] );
		$this->assertSame( 1, preg_match( '/\A[\x20-\x7E]{1,60}\z/', $parsed['errors'][0] ), 'An error line must be printable ASCII of at most 60 bytes.' );
	}

	public function test_a_list_of_21_entries_is_refused_as_a_whole(): void {
		$lines = array();
		for ( $i = 1; $i <= 21; $i++ ) {
			$lines[] = "10.0.0.$i";
		}

		$parsed = IpAllowlist::parse( implode( "\n", $lines ) );

		$this->assertNotSame( array(), $parsed['errors'], 'More than 20 entries must give an error.' );
	}

	public function test_twenty_entries_are_accepted(): void {
		$lines = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$lines[] = "10.0.0.$i";
		}

		$parsed = IpAllowlist::parse( implode( "\n", $lines ) );

		$this->assertSame( array(), $parsed['errors'] );
		$this->assertCount( 20, $parsed['entries'] );
	}

	public function test_at_most_five_error_lines_are_reported(): void {
		$parsed = IpAllowlist::parse( implode( "\n", array_fill( 0, 9, 'example.com' ) ) );

		$this->assertCount( 5, $parsed['errors'] );
	}

	public function test_the_accepted_forms_are_stored_in_canonical_text(): void {
		$parsed = IpAllowlist::parse( "203.0.113.5\n2001:db8::/48\n0.0.0.0/8\n2001:db8::/16\n::1\n" );

		$this->assertSame( array(), $parsed['errors'] );
		$this->assertSame( array( '203.0.113.5', '2001:db8::/48', '0.0.0.0/8', '2001::/16', '::1' ), $parsed['entries'] );
	}

	public function test_a_range_is_masked_to_its_prefix(): void {
		$this->assertSame( array( '203.0.113.0/24' ), IpAllowlist::parse( '203.0.113.7/24' )['entries'] );
		$this->assertSame( array( '2001:db8::/48' ), IpAllowlist::parse( '2001:db8:0:1::5/48' )['entries'] );
	}

	public function test_ipv6_is_written_in_inet_ntop_form(): void {
		$this->assertSame( array( '2001:db8::1' ), IpAllowlist::parse( '2001:DB8:0:0::1' )['entries'] );
	}

	public function test_a_full_length_range_is_stored_as_a_bare_address(): void {
		$this->assertSame( array( '203.0.113.5' ), IpAllowlist::parse( '203.0.113.5/32' )['entries'] );
	}

	public function test_duplicates_after_canonicalisation_are_dropped_and_blank_lines_ignored(): void {
		$parsed = IpAllowlist::parse( "\r\n203.0.113.5\r\n\n   \n203.0.113.5/32\n203.0.113.9/24\n203.0.113.0/24\n" );

		$this->assertSame( array(), $parsed['errors'] );
		$this->assertSame( array( '203.0.113.5', '203.0.113.0/24' ), $parsed['entries'] );
	}

	public function test_an_ipv4_range_matches_its_first_and_last_address_and_not_the_neighbours(): void {
		$entries = array( '203.0.113.0/24' );

		$this->assertTrue( IpAllowlist::matches( '203.0.113.0', $entries ) );
		$this->assertTrue( IpAllowlist::matches( '203.0.113.255', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '203.0.112.255', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '203.0.114.0', $entries ) );
	}

	public function test_a_range_whose_prefix_is_not_a_multiple_of_eight_matches_the_right_bits(): void {
		$entries = array( '10.0.0.0/20' );

		$this->assertTrue( IpAllowlist::matches( '10.0.15.255', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '10.0.16.0', $entries ) );
	}

	public function test_a_23_range_matches_its_first_and_last_address_and_not_the_neighbours(): void {
		$entries = array( '198.51.100.0/23' );

		$this->assertTrue( IpAllowlist::matches( '198.51.100.0', $entries ) );
		$this->assertTrue( IpAllowlist::matches( '198.51.101.255', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '198.51.99.255', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '198.51.102.0', $entries ) );
	}

	public function test_a_61_range_matches_its_first_and_last_address_and_not_the_neighbours(): void {
		$entries = array( '2001:db8:0:8::/61' );

		$this->assertTrue( IpAllowlist::matches( '2001:db8:0:8::', $entries ) );
		$this->assertTrue( IpAllowlist::matches( '2001:db8:0:f:ffff:ffff:ffff:ffff', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '2001:db8:0:7:ffff:ffff:ffff:ffff', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '2001:db8:0:10::', $entries ) );
	}

	public function test_an_ipv6_range_matches_its_first_and_last_address_and_not_the_neighbours(): void {
		$entries = array( '2001:db8::/48' );

		$this->assertTrue( IpAllowlist::matches( '2001:db8::', $entries ) );
		$this->assertTrue( IpAllowlist::matches( '2001:db8:0:ffff:ffff:ffff:ffff:ffff', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '2001:db8:1::', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '2001:db7:ffff:ffff:ffff:ffff:ffff:ffff', $entries ) );
	}

	public function test_an_exact_address_matches_only_itself(): void {
		$entries = array( '203.0.113.5' );

		$this->assertTrue( IpAllowlist::matches( '203.0.113.5', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '203.0.113.4', $entries ) );
		$this->assertFalse( IpAllowlist::matches( '203.0.113.6', $entries ) );
	}

	public function test_an_ipv4_mapped_client_is_compared_as_its_ipv4_address(): void {
		$this->assertTrue( IpAllowlist::matches( '::ffff:203.0.113.5', array( '203.0.113.5' ) ) );
		$this->assertTrue( IpAllowlist::matches( '::ffff:203.0.113.5', array( '203.0.113.0/24' ) ) );
		$this->assertFalse( IpAllowlist::matches( '::ffff:203.0.113.5', array( '203.0.113.6' ) ) );
	}

	public function test_an_ipv4_entry_never_matches_an_ipv6_client_and_an_ipv6_entry_never_matches_ipv4(): void {
		$this->assertFalse( IpAllowlist::matches( '2001:db8::1', array( '203.0.113.5' ) ) );
		$this->assertFalse( IpAllowlist::matches( '203.0.113.5', array( '2001:db8::/32' ) ) );
		$this->assertFalse( IpAllowlist::matches( '2001:db8::1', array( '0.0.0.0/8' ) ) );
	}

	public function test_garbage_matches_nothing(): void {
		$this->assertFalse( IpAllowlist::matches( 'not-an-address', array( '0.0.0.0/8' ) ) );
		$this->assertFalse( IpAllowlist::matches( '', array( '0.0.0.0/8' ) ) );
		$this->assertFalse( IpAllowlist::matches( '203.0.113.5', array() ) );
		$this->assertFalse( IpAllowlist::matches( '203.0.113.5', array( 'garbage', '999.1.1.1/8' ) ) );
	}

	public function test_no_stored_list_is_off(): void {
		$user_id = $this->make_agent();

		$this->assertSame( array(), IpAllowlist::for_user( $user_id ) );
	}

	public function test_an_empty_stored_list_is_off(): void {
		$user_id = $this->make_agent();
		update_user_meta( $user_id, IpAllowlist::META_KEY, array() );

		$this->assertSame( array(), IpAllowlist::for_user( $user_id ) );
	}

	/**
	 * Stored entries are judged by their value. An equivalent spelling, not the text inet_ntop() would print, is a valid list, and it matches.
	 */
	public function test_an_equivalent_spelling_stored_directly_is_valid_and_matches(): void {
		$user_id = $this->make_agent();
		update_user_meta( $user_id, IpAllowlist::META_KEY, array( '2001:0db8:0:0::1' ) );

		$this->assertSame( array( '2001:0db8:0:0::1' ), IpAllowlist::for_user( $user_id ) );
		$this->assertTrue( IpAllowlist::matches( '2001:db8::1', IpAllowlist::for_user( $user_id ) ) );
	}

	public function test_a_stored_canonical_list_is_returned_as_stored(): void {
		$user_id = $this->make_agent();
		update_user_meta( $user_id, IpAllowlist::META_KEY, array( '203.0.113.0/24', '2001:db8::1' ) );

		$this->assertSame( array( '203.0.113.0/24', '2001:db8::1' ), IpAllowlist::for_user( $user_id ) );
	}

	/**
	 * Stored values that are corrupt. Each must read as null, which refuses the agent from every address.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function corrupt_values(): array {
		$too_many = array();
		for ( $i = 1; $i <= 21; $i++ ) {
			$too_many[] = "10.0.0.$i";
		}

		return array(
			'a string'                 => array( '203.0.113.5' ),
			'an integer'               => array( 7 ),
			'a nested array'           => array( array( array( '203.0.113.5' ) ) ),
			'a non-canonical entry'    => array( array( '203.0.113.7/24' ) ),
			'a hostname entry'         => array( array( 'example.com' ) ),
			'a non-string entry'       => array( array( 42 ) ),
			'an entry with a newline'  => array( array( "203.0.113.5\n10.0.0.1" ) ),
			'21 entries'               => array( $too_many ),
			'an associative array'     => array( array( 'office' => '203.0.113.5' ) ),
			'a leading space stored'   => array( array( ' 203.0.113.5' ) ),
			'a trailing space stored'  => array( array( '203.0.113.5 ' ) ),
			'an unmasked range stored' => array( array( '203.0.113.7/24' ) ),
			'a duplicate by value'     => array( array( '2001:db8::1', '2001:0db8::1' ) ),
			'a prefix below the floor' => array( array( '203.0.113.0/7' ) ),
		);
	}

	/**
	 * A corrupt stored value reads as null.
	 *
	 * @dataProvider corrupt_values
	 * @param mixed $value The stored value.
	 */
	public function test_a_corrupt_stored_value_reads_as_null( mixed $value ): void {
		$user_id = $this->make_agent();
		update_user_meta( $user_id, IpAllowlist::META_KEY, $value );

		$this->assertNull( IpAllowlist::for_user( $user_id ) );
	}

	public function test_two_stored_rows_read_as_null(): void {
		$user_id = $this->make_agent();
		add_user_meta( $user_id, IpAllowlist::META_KEY, array( '203.0.113.5' ) );
		add_user_meta( $user_id, IpAllowlist::META_KEY, array( '203.0.113.6' ) );

		$this->assertNull( IpAllowlist::for_user( $user_id ) );
	}

	public function test_the_client_address_is_remote_addr_only(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.5';
		$_SERVER['HTTP_X_REAL_IP']        = '203.0.113.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
		$_SERVER['HTTP_FORWARDED']        = 'for=203.0.113.5';

		$this->assertSame( '203.0.113.9', IpAllowlist::client_ip( 0 ) );
	}

	public function test_a_missing_or_invalid_remote_addr_gives_no_address(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertNull( IpAllowlist::client_ip( 0 ) );

		$_SERVER['REMOTE_ADDR'] = 'not-an-address';
		$this->assertNull( IpAllowlist::client_ip( 0 ) );
	}

	public function test_the_filter_receives_the_remote_addr_and_the_agent_id(): void {
		$_SERVER['REMOTE_ADDR'] = '10.9.8.7';
		$seen                   = array();
		$this->client_filter(
			static function ( $ip, $user_id ) use ( &$seen ) {
				$seen[] = array( $ip, $user_id );
				return $ip;
			}
		);

		$this->assertSame( '10.9.8.7', IpAllowlist::client_ip( 42 ) );
		$this->assertSame( array( array( '10.9.8.7', 42 ) ), $seen );
	}

	public function test_the_filter_may_supply_the_address_of_a_trusted_proxy_header(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->client_filter( static fn() => '203.0.113.5' );

		$this->assertSame( '203.0.113.5', IpAllowlist::client_ip( 0 ) );
	}

	/**
	 * Filter results the client address must not take. Each gives null, which refuses.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusable_filter_results(): array {
		return array(
			'not an address'  => array( 'garbage' ),
			'an empty string' => array( '' ),
			'an array'        => array( array( '203.0.113.5' ) ),
			'an integer'      => array( 2130706433 ),
			'null'            => array( null ),
		);
	}

	/**
	 * A filter result that is not an address gives no address.
	 *
	 * @dataProvider unusable_filter_results
	 * @param mixed $result What the filter returns.
	 */
	public function test_a_filter_result_that_is_not_an_address_gives_no_address( mixed $result ): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->client_filter( static fn() => $result );

		$this->assertNull( IpAllowlist::client_ip( 0 ) );
	}

	public function test_a_throwing_filter_gives_no_address(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->client_filter(
			static function (): void {
				throw new \RuntimeException( 'The proxy rule broke.' );
			}
		);

		$this->assertNull( IpAllowlist::client_ip( 0 ) );
	}

	public function test_an_agent_with_no_list_is_allowed_from_any_address_and_the_filter_is_not_called(): void {
		$user_id                = $this->make_agent();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.200';
		$calls                  = 0;
		$this->client_filter(
			static function ( $ip ) use ( &$calls ) {
				++$calls;
				return $ip;
			}
		);

		$this->assertTrue( IpAllowlist::allows( $user_id ) );
		$this->assertSame( 0, $calls, 'The client address must not be looked up when there is no list.' );
	}

	public function test_an_agent_with_a_list_is_allowed_only_from_a_listed_address(): void {
		$user_id = $this->make_agent();
		update_user_meta( $user_id, IpAllowlist::META_KEY, array( '203.0.113.0/24' ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$this->assertTrue( IpAllowlist::allows( $user_id ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.114.77';
		$this->assertFalse( IpAllowlist::allows( $user_id ) );
	}

	public function test_an_agent_with_a_list_is_refused_when_no_address_can_be_determined(): void {
		$user_id = $this->make_agent();
		update_user_meta( $user_id, IpAllowlist::META_KEY, array( '203.0.113.0/24' ) );
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertFalse( IpAllowlist::allows( $user_id ) );
	}

	public function test_a_corrupt_list_refuses_even_a_listed_address(): void {
		$user_id = $this->make_agent();
		update_user_meta( $user_id, IpAllowlist::META_KEY, 'not a list' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$this->assertFalse( IpAllowlist::allows( $user_id ) );
	}

	public function test_the_evaluation_reports_the_address_it_decided_on(): void {
		$user_id = $this->make_agent();
		update_user_meta( $user_id, IpAllowlist::META_KEY, array( '203.0.113.5' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$this->assertSame(
			array(
				'allowed' => false,
				'ip'      => '203.0.113.9',
			),
			IpAllowlist::evaluate( $user_id )
		);
	}

	public function test_uninstall_removes_the_list_of_every_user(): void {
		$first  = $this->make_agent();
		$second = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $first, IpAllowlist::META_KEY, array( '203.0.113.5' ) );
		update_user_meta( $second, IpAllowlist::META_KEY, array( '203.0.113.6' ) );

		IpAllowlist::uninstall();

		$this->assertSame( array(), get_user_meta( $first, IpAllowlist::META_KEY, false ) );
		$this->assertSame( array(), get_user_meta( $second, IpAllowlist::META_KEY, false ) );
	}
}
