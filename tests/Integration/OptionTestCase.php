<?php
/**
 * Shared fixtures for the update-option tests: an agent that may change options, reads of the options table, and the filters
 * that the tests add removed again.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\OptionRules;

/**
 * Base for the option tests. The fixture options are the ones WordPress installs (blogname and blogdescription), and the tests read
 * them through OptionRules::stored_raw(), the uncached read the rules use.
 */
abstract class OptionTestCase extends PostWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-option';

	/**
	 * The one generic text of an object refusal.
	 */
	protected const NOT_FOUND = 'Not found, or this agent may not access it.';

	/**
	 * Hooks the tests add callbacks to, so tearDown() can remove them.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * Clears the object cache, so a test does not read an option another test changed and then rolled back.
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_cache_flush();

		// The test install does not always hold both built-in options, so the fixture makes sure they exist.
		$fixtures = array(
			'blogname'        => 'Fixture title',
			'blogdescription' => 'Fixture tagline',
		);
		foreach ( $fixtures as $name => $value ) {
			if ( null === OptionRules::stored_raw( $name ) ) {
				add_option( $name, $value, '', 'yes' );
			}
		}
		wp_cache_flush();
	}

	/**
	 * Removes every filter and action the tests added, and clears the cache again.
	 */
	protected function tearDown(): void {
		foreach ( $this->added as $entry ) {
			remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		$this->added = array();

		remove_all_filters( 'steadhand_allowed_options' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Adds a callback to a hook, removed in tearDown().
	 *
	 * @param string   $hook     The hook.
	 * @param callable $callback The callback.
	 * @param int      $priority The priority.
	 * @param int      $args     The number of arguments the callback takes.
	 */
	protected function on( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * Makes the current user a dedicated agent that holds steadhand_settings and steadhand_publish, and returns its ID.
	 *
	 * @param array<string, bool> $overrides Capability overrides, applied after the grants.
	 */
	protected function option_agent( array $overrides = array() ): int {
		return $this->act_as_agent(
			array_merge(
				array(
					Capabilities::SETTINGS => true,
					Capabilities::PUBLISH  => true,
				),
				$overrides
			)
		);
	}

	/**
	 * The arguments of a call.
	 *
	 * @param string      $option   The option name.
	 * @param mixed       $value    The value.
	 * @param string|null $expected The expected value, or null to send none.
	 * @return array<string, mixed>
	 */
	protected function args( string $option, mixed $value, ?string $expected = null ): array {
		$args = array(
			'option' => $option,
			'value'  => $value,
		);
		if ( null !== $expected ) {
			$args['expected'] = $expected;
		}

		return $args;
	}

	/**
	 * The stored value of an option, read uncached. Fails the test when there is no row.
	 *
	 * @param string $name The option name.
	 */
	protected function stored_value( string $name ): string {
		$value = OptionRules::stored_raw( $name );
		$this->assertIsString( $value, "The option $name must exist." );

		return $value;
	}

	/**
	 * The autoload setting of an option's row, read from the table.
	 *
	 * @param string $name The option name.
	 */
	protected function autoload_of( string $name ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the options table directly.
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is core's.
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Asserts a refusal with a Steadhand code, and returns nothing.
	 *
	 * @param mixed  $result  The call's result.
	 * @param string $code    The expected code.
	 * @param int    $status  The expected HTTP status in the data.
	 */
	protected function assert_refused_with( mixed $result, string $code, int $status ): void {
		$this->assertWPError( $result, 'The call must be refused.' );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
