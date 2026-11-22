<?php
/**
 * Lockdown, wp-admin belt and registration.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Registry;
use Steadhand\Lockdown;
use Steadhand\Plugin;
use Steadhand\Requirements;
use Steadhand\Server;

/**
 * Covers the admin_init rule in Lockdown (method block_agents_in_admin()) and the wiring rules in
 * Plugin::boot() (SPEC §3.2 / §14.1).
 *
 * The admin_init rule is defence in depth. Under normal requests determine_current_user already drops an
 * agent unless it used an application password, so the rule is reached only when an application password
 * is accepted outside the API (for example through the application_password_is_api_request filter).
 */
final class LockdownAdminTest extends TestCase {

	/**
	 * Hooks that Lockdown attaches to in register().
	 */
	private const HOOKS = array( 'authenticate', 'determine_current_user', 'rest_pre_dispatch', 'rest_request_before_callbacks', 'admin_init' );

	/**
	 * Hooks that only the ability and server wiring attaches to. Missing dependencies must leave these alone.
	 */
	private const DEPENDENT_HOOKS = array( 'wp_abilities_api_categories_init', 'wp_abilities_api_init', 'mcp_adapter_init' );

	/**
	 * Counts the callbacks on a hook whose object is an instance of the given class.
	 *
	 * @param string $hook Hook name.
	 * @param string $type Class of the callback's object, for example Lockdown::class.
	 * @return int
	 */
	private function callbacks_of( string $hook, string $type ): int {
		$filters = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $filters instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;
		foreach ( $filters->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof $type ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Guards block_agents_in_admin() for a pure agent. wp_die() is called with status 403, and the
	 * test captures the arguments through the wp_die_handler filter to check the status.
	 */
	public function test_an_agent_is_stopped_at_admin_init_with_a_403(): void {
		wp_set_current_user( $this->make_agent() );
		$captured = null;
		$handler  = static function ( $message, $title = '', $args = array() ) use ( &$captured ): void {
			unset( $message, $title );
			$captured = $args;
			throw new \WPDieException( 'wp_die reached' );
		};
		add_filter( 'wp_die_handler', static fn () => $handler, PHP_INT_MAX );

		try {
			do_action( 'admin_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			$this->fail( 'admin_init must stop an agent with wp_die().' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'wp_die reached', $e->getMessage() );
		}

		$this->assertIsArray( $captured );
		$this->assertSame( 403, $captured['response'] ?? null );
	}

	/**
	 * Negative control: an administrator is not stopped by the agent rule. The rule's method is called
	 * directly, because running every admin_init callback in the CLI test process makes core send headers,
	 * and that produces unrelated warnings. Calling the method is the same code the hook runs.
	 */
	public function test_an_administrator_is_not_stopped_by_the_admin_rule(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wp_died = false;
		add_filter(
			'wp_die_handler',
			static function () use ( &$wp_died ) {
				return static function () use ( &$wp_died ): void {
					$wp_died = true;
					throw new \WPDieException( 'unexpected wp_die' );
				};
			},
			PHP_INT_MAX
		);

		( new Lockdown() )->block_agents_in_admin();

		$this->assertFalse( $wp_died );
	}

	/**
	 * Guards Plugin::boot(): when requirements are met, every Lockdown hook is registered. The bootstrap runs
	 * boot() on plugins_loaded, so the hooks are already present when the tests start.
	 */
	public function test_lockdown_hooks_are_registered_when_requirements_are_met(): void {
		foreach ( self::HOOKS as $hook ) {
			$this->assertGreaterThan( 0, $this->callbacks_of( $hook, Lockdown::class ), "$hook must have a Lockdown callback." );
		}
	}

	/**
	 * Guards the order in Plugin::boot() (SPEC §3.2): the lockdown depends only on WordPress core, so it is registered
	 * even when the MCP Adapter or the Abilities API is missing. Only the ability and server wiring is skipped.
	 * Before the fix the lockdown was skipped too, which left agent credentials working on core REST and wp-login.
	 */
	public function test_lockdown_is_registered_even_when_requirements_are_not_met(): void {
		$lockdown_before  = array();
		$dependent_before = array();
		foreach ( self::HOOKS as $hook ) {
			$lockdown_before[ $hook ] = $this->callbacks_of( $hook, Lockdown::class );
		}
		foreach ( self::DEPENDENT_HOOKS as $hook ) {
			$dependent_before[ $hook ] = $this->callbacks_of( $hook, Registry::class ) + $this->callbacks_of( $hook, Server::class );
		}

		$booted = ( new Plugin() )->boot( new Requirements( false, false ) );

		$this->assertFalse( $booted );
		foreach ( self::HOOKS as $hook ) {
			$this->assertGreaterThan( $lockdown_before[ $hook ], $this->callbacks_of( $hook, Lockdown::class ), "$hook must gain a Lockdown callback even without requirements." );
		}
		foreach ( self::DEPENDENT_HOOKS as $hook ) {
			$this->assertSame(
				$dependent_before[ $hook ],
				$this->callbacks_of( $hook, Registry::class ) + $this->callbacks_of( $hook, Server::class ),
				"$hook must not gain an ability or server callback when requirements are missing."
			);
		}
	}
}
