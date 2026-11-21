<?php
/**
 * Plugin wiring: builds the services and hooks them into WordPress.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Composes Steadhand's services and registers their hooks. Holds no logic of its own.
 */
final class Plugin {

	/**
	 * Ability registry.
	 *
	 * @var Abilities\Registry
	 */
	private Abilities\Registry $registry;

	/**
	 * MCP server registration.
	 *
	 * @var Server
	 */
	private Server $server;

	/**
	 * Constructor. Builds the service graph; registers no hooks.
	 */
	public function __construct() {
		$pipeline       = new Pipeline\Pipeline( new Log\NullLogger() );
		$this->registry = new Abilities\Registry( $pipeline );
		$this->server   = new Server( $this->registry );
	}

	/**
	 * Returns the ability registry.
	 *
	 * @return Abilities\Registry
	 */
	public function registry(): Abilities\Registry {
		return $this->registry;
	}

	/**
	 * Returns the MCP server registration.
	 *
	 * @return Server
	 */
	public function server(): Server {
		return $this->server;
	}

	/**
	 * Hooks Steadhand into WordPress, or shows a notice if a dependency is missing.
	 *
	 * @param Requirements $requirements The detected dependency state.
	 * @return bool True when Steadhand's hooks were registered.
	 */
	public function boot( Requirements $requirements ): bool {
		if ( ! $requirements->are_met() ) {
			add_action( 'admin_notices', array( $requirements, 'render_notice' ) );
			return false;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this->registry, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this->registry, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( $this->server, 'register' ) );

		return true;
	}
}
