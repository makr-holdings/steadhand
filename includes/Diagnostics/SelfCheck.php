<?php
/**
 * The Steadhand self-check: whether the plugin's own pieces are registered, read from the live registries.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Diagnostics;

use Steadhand\Abilities\PostReader;
use Steadhand\Abilities\Registry;
use Steadhand\Lockdown;
use Steadhand\Log\NullLogger;
use Steadhand\Log\Schema;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Checks each piece against the registry that holds it (SPEC §3.3): the ability category and each ability in core, the
 * server in the MCP Adapter, and the lockdown hooks in WordPress. Nothing here returns a constant.
 */
final class SelfCheck {

	/**
	 * The lockdown hooks that Lockdown::register() attaches, by hook name and method.
	 */
	private const LOCKDOWN_HOOKS = array(
		'authenticate'                                => 'refuse_agent_login',
		'determine_current_user'                      => 'refuse_non_application_password_identity',
		'rest_pre_dispatch'                           => 'restrict_agent_routes',
		'rest_request_before_callbacks'               => 'restrict_agent_callbacks',
		'admin_init'                                  => 'block_agents_in_admin',
		'wp_authenticate_application_password_errors' => 'refuse_agent_from_unlisted_address',
	);

	/**
	 * Builds the steadhand section.
	 *
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		return array(
			'version'                     => PostReader::cut_utf8( STEADHAND_VERSION, 20 ),
			'mcp_adapter_version'         => self::adapter_version(),
			'ability_category_registered' => function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( Registry::CATEGORY ),
			'abilities_registered'        => self::abilities_registered(),
			'server_registered'           => self::server_registered(),
			'lockdown_active'             => self::lockdown_active(),
			'change_log_ready'            => Schema::ready(),
			'snapshots_ready'             => Schema::snapshots_ready(),
		);
	}

	/**
	 * The version of the MCP Adapter, from its own version constant, or '' when it is not loaded.
	 *
	 * @return string
	 */
	private static function adapter_version(): string {
		$constant = '\WP\MCP\Core\McpAdapter::VERSION';

		return defined( $constant ) ? PostReader::cut_utf8( (string) constant( $constant ), 40 ) : '';
	}

	/**
	 * How many of Steadhand's abilities core has registered. The names come from the same registry the plugin uses.
	 *
	 * @return int
	 */
	private static function abilities_registered(): int {
		if ( ! function_exists( 'wp_has_ability' ) ) {
			return 0;
		}

		$names = ( new Registry( new Pipeline( new NullLogger() ) ) )->names();

		return count( array_filter( $names, static fn( string $name ): bool => wp_has_ability( $name ) ) );
	}

	/**
	 * Whether the Steadhand server is registered with the MCP Adapter.
	 *
	 * @return bool
	 */
	private static function server_registered(): bool {
		$adapter = '\WP\MCP\Core\McpAdapter';
		if ( ! class_exists( $adapter ) || ! method_exists( $adapter, 'instance' ) ) {
			return false;
		}

		return self::has_server( $adapter::instance() );
	}

	/**
	 * Whether a MCP Adapter instance has the Steadhand server. An instance without a get_server() method has no server,
	 * so the answer is false rather than a fatal error.
	 *
	 * @param object $adapter The MCP Adapter instance.
	 * @return bool
	 */
	public static function has_server( object $adapter ): bool {
		if ( ! method_exists( $adapter, 'get_server' ) ) {
			return false;
		}

		return null !== $adapter->get_server( Server::ID );
	}

	/**
	 * Whether every lockdown hook is attached.
	 *
	 * @return bool
	 */
	private static function lockdown_active(): bool {
		foreach ( self::LOCKDOWN_HOOKS as $hook => $method ) {
			if ( ! self::has_lockdown_callback( $hook, $method ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a hook has a callback that is the given method of a Lockdown object.
	 *
	 * @param string $hook   Hook name.
	 * @param string $method Method name.
	 * @return bool
	 */
	private static function has_lockdown_callback( string $hook, string $method ): bool {
		global $wp_filter;

		$registered = $wp_filter[ $hook ] ?? null;
		if ( ! $registered instanceof \WP_Hook ) {
			return false;
		}

		foreach ( $registered->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && ( $function[0] ?? null ) instanceof Lockdown && ( $function[1] ?? '' ) === $method ) {
					return true;
				}
			}
		}

		return false;
	}
}
